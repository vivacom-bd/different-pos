<?php

declare(strict_types=1);

namespace App\Support\Catalog;

use App\Enums\ProductItemType;
use App\Models\BranchStock;
use App\Models\Product;
use App\Support\Quantity\Quantity;

/** Scan-field / picker lookup (finished products and raw materials; `$only` narrows to one kind): exact barcode or SKU first, else a name or SKU-prefix search. Carries the current branch's on-hand quantity. */
final class ProductLookup
{
    /** @return array{exact: bool, products: list<array<string, mixed>>} */
    public function find(string $code, bool $withCost, ?ProductItemType $only = null): array
    {
        $code = trim($code);
        if ($code === '') {
            return ['exact' => false, 'products' => []];
        }

        $scoped = static fn () => Product::query()->where('is_active', true)
            ->when($only instanceof ProductItemType, static fn ($q) => $q->where('item_type', $only?->value));

        $exact = $scoped()
            ->where(static fn ($q) => $q->where('barcode', $code)->orWhere('sku', $code))->limit(1)->get();
        $products = $exact->isNotEmpty() ? $exact : $scoped()
            ->search($code)->orderByCodeMatch($code)
            ->orderBy('name')->limit(8)->get();
        $products->load('unit:id,short_name,allows_decimal');

        /** @var array<int, Quantity> $onHand */
        $onHand = BranchStock::query()->whereIn('product_id', $products->pluck('id')->all())->get(['product_id', 'qty_on_hand'])
            ->mapWithKeys(static fn (BranchStock $s): array => [$s->product_id => $s->qty_on_hand])->all();

        return [
            'exact' => $exact->isNotEmpty(),
            'products' => array_values($products->map(fn (Product $p): array => $this->present($p, $withCost, $onHand[$p->id] ?? Quantity::zero()))->all()),
        ];
    }

    /**
     * One lookup row (also the answer of the GRN's inline create).
     *
     * @return array<string, mixed>
     */
    public function present(Product $p, bool $withCost, Quantity $onHand): array
    {
        $p->loadMissing('unit:id,short_name,allows_decimal');

        return [
            'id' => $p->id,
            'name' => $p->name,
            'variant_label' => $p->variant_label,
            'sku' => $p->sku,
            'barcode' => $p->barcode,
            'item_type' => $p->item_type->value,
            'unit' => $p->unit?->short_name,
            'allows_decimal' => (bool) $p->unit?->allows_decimal,
            'track_stock' => $p->track_stock,
            'cost_price' => $withCost ? $p->cost_price->paisa : 0,
            'sale_price' => $p->sale_price->paisa,
            'on_hand' => $onHand->milli,
        ];
    }
}
