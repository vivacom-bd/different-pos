<?php

declare(strict_types=1);

namespace App\Support\Pos;

use App\Models\BranchStock;
use App\Models\Product;
use App\Models\ProductFamily;
use App\Models\TaxRate;
use App\Models\Unit;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Storage;

/**
 * Register catalogue: products of the current tenant with stock at the current branch. A variant is
 * returned inside its family (every active variant, for the picker); a standalone product is a
 * family of one. An exact barcode or SKU hit returns just that product, ready to add.
 * Cost never leaves here.
 */
final readonly class ProductLookup
{
    private const int LIMIT = 40;

    /** @return array{exact: bool, items: list<array<string, mixed>>} */
    public function find(string $term, ?int $categoryId = null): array
    {
        $term = mb_substr(trim($term), 0, 60);

        if ($term !== '') {
            $exact = Product::query()->sellable()->where('is_active', true)
                ->where(fn (Builder $q) => $q->where('barcode', $term)->orWhere('sku', $term))
                ->orderByRaw('barcode = ? desc', [$term])
                ->first();
            if ($exact instanceof Product) {
                return ['exact' => true, 'items' => [$this->standalone($exact)]];
            }
        }

        $query = Product::query()->sellable()->where('is_active', true);
        if ($categoryId !== null) {
            $query->where('category_id', $categoryId);
        }
        if ($term !== '') {
            $like = '%'.addcslashes($term, '\\%_').'%';
            $prefix = addcslashes($term, '\\%_').'%';
            $families = ProductFamily::query()->where(fn (Builder $q) => $q->where('name', 'like', $like)->orWhere('code', 'like', $prefix))->limit(self::LIMIT)->pluck('id')->all();
            $query->where(fn (Builder $q) => $q->search($term)
                ->when($families !== [], fn (Builder $w) => $w->orWhereIn('family_id', $families)));
        }

        $hits = $query->when($term !== '', fn (Builder $q) => $q->orderByCodeMatch($term))->orderBy('name')->limit(self::LIMIT * 4)->get(['id', 'family_id']);

        $familyIds = [];
        $productIds = [];
        foreach ($hits as $hit) {
            $key = $hit->family_id === null ? 'p'.$hit->id : 'f'.$hit->family_id;
            if (isset($familyIds[$key]) || isset($productIds[$key])) {
                continue;
            }
            if (count($familyIds) + count($productIds) >= self::LIMIT) {
                break;
            }
            if ($hit->family_id === null) {
                $productIds[$key] = $hit->id;
            } else {
                $familyIds[$key] = $hit->family_id;
            }
        }

        $products = Product::query()->sellable()->where('is_active', true)
            ->where(fn (Builder $q) => $q->whereIn('id', array_values($productIds))->orWhereIn('family_id', array_values($familyIds)))
            ->with(['option1Value', 'option2Value'])
            ->orderBy('option1_value_id')->orderBy('option2_value_id')->orderBy('id')
            ->get();
        $families = ProductFamily::query()->whereIn('id', array_values($familyIds))->get()->keyBy('id');
        $context = $this->context($products);

        $items = [];
        foreach (array_keys($familyIds + $productIds) as $key) {
            if (isset($productIds[$key])) {
                $product = $products->firstWhere('id', $productIds[$key]);
                if ($product instanceof Product) {
                    $items[] = $this->item('p'.$product->id, 'product', $product->name, $product->name_bn, $product->sku, $product->category_id, $product->image_path, [$product], $context);
                }

                continue;
            }

            $family = $families->get($familyIds[$key]);
            $variants = array_values($products->where('family_id', $familyIds[$key])->all());
            if ($family instanceof ProductFamily && $variants !== []) {
                $items[] = $this->item('f'.$family->id, 'family', $family->name, $family->name_bn, $family->code, $family->category_id, $family->image_path, $variants, $context);
            }
        }

        return ['exact' => false, 'items' => $items];
    }

    /** @return array<string, mixed> */
    private function standalone(Product $product): array
    {
        $product->load(['option1Value', 'option2Value']);
        $context = $this->context(new Collection([$product]));

        return $this->item('p'.$product->id, 'product', $product->name, $product->name_bn, $product->sku, $product->category_id, $product->image_path, [$product], $context);
    }

    /**
     * @param  list<Product>  $variants
     * @param  array{stock: array<int, int>, rates: array<int, int>, decimals: array<int, bool>}  $context
     * @return array<string, mixed>
     */
    private function item(string $key, string $kind, string $name, ?string $nameBn, string $code, ?int $categoryId, ?string $image, array $variants, array $context): array
    {
        $rows = array_map(fn (Product $p): array => $this->variant($p, $context), $variants);
        $prices = array_map(static fn (array $row): int => $row['price'], $rows);
        $tracked = array_filter($rows, static fn (array $row): bool => $row['stock'] !== null);

        return [
            'key' => $key,
            'kind' => $kind,
            'name' => $name,
            'name_bn' => $nameBn,
            'code' => $code,
            'category_id' => $categoryId,
            'image_url' => $image === null ? null : Storage::disk('public')->url($image),
            'price' => $prices === [] ? 0 : min($prices),
            'stock' => $tracked === [] ? null : array_sum(array_map(static fn (array $row): int => (int) $row['stock'], $tracked)),
            'variants' => $rows,
        ];
    }

    /**
     * @param  array{stock: array<int, int>, rates: array<int, int>, decimals: array<int, bool>}  $context
     * @return array<string, mixed>
     */
    private function variant(Product $product, array $context): array
    {
        return [
            'id' => $product->id,
            'name' => $product->name,
            'sku' => $product->sku,
            'barcode' => $product->barcode,
            'label' => $product->variant_label,
            'option1' => $product->option1Value?->value,
            'option2' => $product->option2Value?->value,
            'price' => $product->sale_price->paisa,
            'tax_rate_bp' => $product->tax_rate_id === null ? 0 : ($context['rates'][$product->tax_rate_id] ?? 0),
            'stock' => $product->track_stock ? ($context['stock'][$product->id] ?? 0) : null,
            'allows_decimal' => $context['decimals'][$product->unit_id] ?? false,
        ];
    }

    /**
     * Branch stock, tax rates and unit decimals for a set of products, three queries.
     *
     * @param  Collection<int, Product>  $products
     * @return array{stock: array<int, int>, rates: array<int, int>, decimals: array<int, bool>}
     */
    private function context(Collection $products): array
    {
        $stock = [];
        foreach (BranchStock::query()->whereIn('product_id', $products->pluck('id')->all())->get(['product_id', 'qty_on_hand']) as $row) {
            $stock[$row->product_id] = $row->qty_on_hand->milli;
        }

        $rateIds = $products->pluck('tax_rate_id')->filter()->unique()->values()->all();
        $rates = [];
        foreach ($rateIds === [] ? [] : TaxRate::query()->whereIn('id', $rateIds)->get(['id', 'rate_bp']) as $rate) {
            $rates[$rate->id] = (int) $rate->rate_bp;
        }

        $decimals = [];
        foreach (Unit::query()->whereIn('id', $products->pluck('unit_id')->unique()->values()->all())->get(['id', 'allows_decimal']) as $unit) {
            $decimals[$unit->id] = $unit->allows_decimal;
        }

        return ['stock' => $stock, 'rates' => $rates, 'decimals' => $decimals];
    }
}
