<?php

declare(strict_types=1);

namespace App\Actions\Catalog;

use App\Enums\ProductItemType;
use App\Enums\ProductType;
use App\Enums\StockMovementType;
use App\Exceptions\Domain\InvalidCatalogData;
use App\Models\Brand;
use App\Models\Category;
use App\Models\Product;
use App\Models\SaleItem;
use App\Models\TaxRate;
use App\Models\Unit;
use App\Models\User;
use App\Models\Vendor;
use App\Support\Audit\Audit;
use App\Support\Catalog\BarcodeAllocator;
use App\Support\Catalog\SkuAllocator;
use App\Support\Catalog\Values;
use App\Support\Database\Transaction;
use App\Support\Money\Money;
use App\Support\Quantity\Quantity;
use App\Support\Stock\StockLedger;
use App\Support\Tenancy\TenantContext;

/**
 * Creates or updates a product. SKU is unique per tenant, a barcode is unique and may not start
 * with MC (membership cards); a blank barcode on create gets an auto EAN-13. Family membership is
 * not touched here (see GenerateFamilyVariants / AttachProductToFamily).
 *
 * `opening_qty` (create only, stock-tracked, needs stock.adjust) receives that quantity into the current
 * branch as an opening batch at the product's cost price, through StockLedger in the same transaction.
 *
 * @phpstan-type ProductData array{
 *     name?: string, name_bn?: string|null, sku?: string, barcode?: string|null, hs_code?: string|null,
 *     type?: ProductType|string, item_type?: ProductItemType|string, category_id?: int|null, brand_id?: int|null, unit_id?: int,
 *     tax_rate_id?: int|null, default_vendor_id?: int|null, sale_price?: Money|string,
 *     cost_price?: Money|string, min_sale_price?: Money|string|null,
 *     wholesale_price?: Money|string|null, min_sale_qty?: Quantity|string,
 *     reorder_level?: Quantity|string, track_stock?: bool, image_path?: string|null,
 *     description?: string|null, is_active?: bool, opening_qty?: Quantity|string|null
 * }
 */
final readonly class SaveProduct
{
    public function __construct(
        private BarcodeAllocator $barcodes,
        private SkuAllocator $skus,
        private StockLedger $ledger,
        private TenantContext $context,
    ) {}

    /** @param ProductData $data */
    public function handle(User $actor, array $data, ?Product $product = null): Product
    {
        return Transaction::run(function () use ($actor, $data, $product): Product {
            $product = $product instanceof Product ? Product::query()->findOrFail($product->id) : null;
            $created = ! $product instanceof Product;
            $product ??= new Product;

            $name = trim((string) ($data['name'] ?? ($created ? '' : $product->name)));
            if ($name === '') {
                throw InvalidCatalogData::required('Name');
            }
            $rawItemType = $data['item_type'] ?? ($created ? ProductItemType::Finished : $product->item_type);
            $itemType = $rawItemType instanceof ProductItemType ? $rawItemType : (ProductItemType::tryFrom($rawItemType) ?? throw InvalidCatalogData::invalid('Unknown item type.'));
            $raw = $itemType === ProductItemType::RawMaterial;

            $sku = trim((string) ($data['sku'] ?? ($created ? '' : $product->sku)));
            if ($sku === '') {
                $sku = $created ? $this->skus->next($itemType) : throw InvalidCatalogData::required('SKU');
            }
            $this->assertSkuFree($sku, $created ? null : $product->id);

            // A raw material is bought and stocked, never sold: no retail price.
            $attributes = [
                'name' => $name,
                'sku' => $sku,
                'item_type' => $itemType,
                'sale_price' => $raw
                    ? Money::zero()
                    : Values::money($data['sale_price'] ?? ($created ? null : $product->sale_price) ?? throw InvalidCatalogData::required('Sale price')),
            ];
            foreach (['name_bn', 'hs_code', 'image_path', 'description', 'is_active'] as $key) {
                if (array_key_exists($key, $data)) {
                    $attributes[$key] = $data[$key];
                }
            }
            if (array_key_exists('cost_price', $data)) {
                $attributes['cost_price'] = Values::money($data['cost_price']);
            }
            foreach (['min_sale_price', 'wholesale_price'] as $key) {
                if (array_key_exists($key, $data)) {
                    $attributes[$key] = Values::moneyOrNull($data[$key]);
                }
            }
            foreach (['min_sale_qty', 'reorder_level'] as $key) {
                if (array_key_exists($key, $data)) {
                    $attributes[$key] = Values::quantity($data[$key]);
                }
            }
            if ($raw) {
                $attributes['min_sale_price'] = null;
                $attributes['wholesale_price'] = null;
            }
            $rawType = $data['type'] ?? ($created ? ProductType::Standard : $product->type);
            $type = $rawType instanceof ProductType ? $rawType : (ProductType::tryFrom($rawType) ?? throw InvalidCatalogData::invalid('Unknown product type.'));
            $attributes['type'] = $type;
            $attributes['track_stock'] = $type === ProductType::Service ? false : (bool) ($data['track_stock'] ?? ($created ? true : $product->track_stock));
            if ($raw) {
                $this->assertCanBeRawMaterial($product, $created, $type);
                $attributes['track_stock'] = true;
            }

            $this->assertNonNegative($attributes);
            $this->assertReferences($data, $attributes, $created);

            $barcode = trim((string) ($data['barcode'] ?? ''));
            if ($barcode === '' && ! $created) {
                $barcode = (string) $product->barcode; // a blank barcode never clears an existing one
            }
            if ($barcode !== '') {
                if (strncasecmp($barcode, 'MC', 2) === 0) {
                    throw InvalidCatalogData::reservedBarcode();
                }
                $taken = Product::withTrashed()->where('barcode', $barcode)->when(! $created, fn ($q) => $q->whereKeyNot($product->id))->exists();
                if ($taken) {
                    throw InvalidCatalogData::duplicate('barcode', $barcode);
                }
            } else {
                $barcode = $this->barcodes->next();
            }
            $attributes['barcode'] = $barcode;

            $product->fill($attributes);
            if ($created) {
                $product->created_by = $actor->id;
            }
            $product->updated_by = $actor->id;
            $product->save();

            $this->receiveOpeningStock($actor, $product, $created, $data['opening_qty'] ?? null);

            Audit::log($created ? 'product.created' : 'product.updated', $product, ['sku' => $sku], $actor);

            return $product;
        });
    }

    private function receiveOpeningStock(User $actor, Product $product, bool $created, Quantity|string|null $raw): void
    {
        if ($raw === null || $raw === '') {
            return;
        }
        $qty = Values::quantity($raw);
        if ($qty->isZero()) {
            return;
        }
        if ($qty->isNegative()) {
            throw InvalidCatalogData::invalid('Opening stock must not be negative.');
        }
        if (! $created) {
            throw InvalidCatalogData::invalid('Opening stock can only be set when the product is created.');
        }
        if (! $product->track_stock) {
            throw InvalidCatalogData::invalid('Opening stock needs a stock-tracked product.');
        }
        if (! $actor->can('stock.adjust')) {
            throw InvalidCatalogData::invalid('You are not allowed to record opening stock.');
        }

        $this->ledger->receive(
            $product,
            $this->context->branch(),
            $qty,
            $product->cost_price,
            StockMovementType::Opening,
            null,
            ['type' => 'products', 'id' => $product->id, 'user_id' => $actor->id, 'note' => 'opening'],
        );
    }

    /** A raw material is a standalone, stock-tracked item that has never been sold. */
    private function assertCanBeRawMaterial(Product $product, bool $created, ProductType $type): void
    {
        if ($type === ProductType::Service || (! $created && $product->family_id !== null)) {
            throw InvalidCatalogData::rawMaterialNeedsStandalone();
        }
        if (! $created && $product->item_type === ProductItemType::Finished && SaleItem::query()->where('product_id', $product->id)->exists()) {
            throw InvalidCatalogData::rawMaterialHasSales($product->name);
        }
    }

    private function assertSkuFree(string $sku, ?int $exceptId): void
    {
        $taken = Product::withTrashed()->where('sku', $sku)->when($exceptId !== null, fn ($q) => $q->whereKeyNot($exceptId))->exists();
        if ($taken) {
            throw InvalidCatalogData::duplicate('product code', $sku);
        }
    }

    /** @param array<string, mixed> $attributes */
    private function assertNonNegative(array $attributes): void
    {
        foreach (['sale_price', 'cost_price', 'min_sale_price', 'wholesale_price'] as $key) {
            if (($attributes[$key] ?? null) instanceof Money && $attributes[$key]->isNegative()) {
                throw InvalidCatalogData::invalid('Prices must not be negative.');
            }
        }
        foreach (['min_sale_qty', 'reorder_level'] as $key) {
            if (($attributes[$key] ?? null) instanceof Quantity && $attributes[$key]->isNegative()) {
                throw InvalidCatalogData::invalid('Quantities must not be negative.');
            }
        }
    }

    /**
     * Foreign keys must resolve inside the current tenant (the scoped lookup 404s otherwise).
     *
     * @param  array<string, mixed>  $data
     * @param  array<string, mixed>  $attributes
     */
    private function assertReferences(array $data, array &$attributes, bool $creating): void
    {
        $map = [
            'category_id' => Category::class, 'brand_id' => Brand::class, 'unit_id' => Unit::class,
            'tax_rate_id' => TaxRate::class, 'default_vendor_id' => Vendor::class,
        ];
        foreach ($map as $key => $model) {
            if (array_key_exists($key, $data)) {
                if ($data[$key] !== null) {
                    $model::query()->findOrFail($data[$key]);
                }
                $attributes[$key] = $data[$key];
            }
        }
        if ($creating && ! isset($data['unit_id'])) {
            throw InvalidCatalogData::required('Unit');
        }
    }
}
