<?php

declare(strict_types=1);

namespace App\Models;

use App\Casts\MoneyCast;
use App\Casts\QuantityCast;
use App\Enums\ProductItemType;
use App\Enums\ProductType;
use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\HasUuid;
use App\Models\Concerns\LogsTenantActivity;
use App\Models\Concerns\Syncable;
use App\Support\Money\Money;
use App\Support\Quantity\Quantity;
use Database\Factories\ProductFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * The only sellable, stock-keeping unit; a variant is a product that belongs to a family.
 *
 * @property int $id
 * @property string $uuid
 * @property int $tenant_id
 * @property int|null $family_id
 * @property int|null $option1_value_id
 * @property int|null $option2_value_id
 * @property string|null $variant_label
 * @property string $variant_key
 * @property ProductType $type
 * @property ProductItemType $item_type
 * @property string $name
 * @property string|null $name_bn
 * @property string $sku
 * @property string|null $barcode
 * @property string|null $hs_code
 * @property int|null $category_id
 * @property int|null $brand_id
 * @property int $unit_id
 * @property int|null $tax_rate_id
 * @property int|null $default_vendor_id
 * @property Money $sale_price
 * @property Money $cost_price
 * @property Money|null $min_sale_price
 * @property Money|null $wholesale_price
 * @property Quantity $min_sale_qty
 * @property Quantity $reorder_level
 * @property bool $track_stock
 * @property string|null $image_path
 * @property string|null $description
 * @property bool $is_active
 * @property int|null $sync_version
 * @property int|null $legacy_id
 * @property int|null $created_by
 * @property int|null $updated_by
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property Carbon|null $deleted_at
 */
final class Product extends Model
{
    use BelongsToTenant, HasUuid, LogsTenantActivity, SoftDeletes, Syncable;

    /** @use HasFactory<ProductFactory> */
    use HasFactory;

    protected $attributes = ['item_type' => 'finished'];

    protected $fillable = [
        'family_id', 'option1_value_id', 'option2_value_id', 'variant_label', 'type', 'item_type', 'name', 'name_bn', 'sku',
        'barcode', 'hs_code', 'category_id', 'brand_id', 'unit_id', 'tax_rate_id', 'default_vendor_id', 'sale_price',
        'cost_price', 'min_sale_price', 'wholesale_price', 'min_sale_qty', 'reorder_level', 'track_stock', 'image_path', 'description',
        'is_active', 'legacy_id', 'created_by', 'updated_by',
    ];

    /**
     * Products that may appear on a sale: everything except raw materials.
     *
     * @param  Builder<Product>  $query
     * @return Builder<Product>
     */
    public function scopeSellable(Builder $query): Builder
    {
        return $query->where('products.item_type', ProductItemType::Finished->value);
    }

    /**
     * Shared product search: every whitespace-separated word must match name, name_bn, sku (the product
     * code) or barcode (contains).
     *
     * @param  Builder<Product>  $query
     * @return Builder<Product>
     */
    public function scopeSearch(Builder $query, string $term): Builder
    {
        foreach (preg_split('/\s+/u', trim($term), -1, PREG_SPLIT_NO_EMPTY) ?: [] as $word) {
            $like = '%'.addcslashes($word, '%_\\').'%';
            $query->where(static fn (Builder $q): Builder => $q->where('products.name', 'like', $like)
                ->orWhere('products.name_bn', 'like', $like)
                ->orWhere('products.sku', 'like', $like)
                ->orWhere('products.barcode', 'like', $like));
        }

        return $query;
    }

    /**
     * Exact sku/barcode first, then code prefix, then the rest.
     *
     * @param  Builder<Product>  $query
     * @return Builder<Product>
     */
    public function scopeOrderByCodeMatch(Builder $query, string $term): Builder
    {
        $term = trim($term);
        $prefix = addcslashes($term, '%_\\').'%';

        return $query->orderByRaw(
            'CASE WHEN products.sku = ? OR products.barcode = ? THEN 0 WHEN products.sku LIKE ? OR products.barcode LIKE ? THEN 1 ELSE 2 END',
            [$term, $term, $prefix, $prefix],
        );
    }

    public function isRawMaterial(): bool
    {
        return $this->item_type === ProductItemType::RawMaterial;
    }

    /** @return BelongsTo<ProductFamily, $this> */
    public function family(): BelongsTo
    {
        return $this->belongsTo(ProductFamily::class, 'family_id');
    }

    /** @return BelongsTo<VariantOptionValue, $this> */
    public function option1Value(): BelongsTo
    {
        return $this->belongsTo(VariantOptionValue::class, 'option1_value_id');
    }

    /** @return BelongsTo<VariantOptionValue, $this> */
    public function option2Value(): BelongsTo
    {
        return $this->belongsTo(VariantOptionValue::class, 'option2_value_id');
    }

    /** @return BelongsTo<Category, $this> */
    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class, 'category_id');
    }

    /** @return BelongsTo<Brand, $this> */
    public function brand(): BelongsTo
    {
        return $this->belongsTo(Brand::class, 'brand_id');
    }

    /** @return BelongsTo<Unit, $this> */
    public function unit(): BelongsTo
    {
        return $this->belongsTo(Unit::class, 'unit_id');
    }

    /** @return BelongsTo<TaxRate, $this> */
    public function taxRate(): BelongsTo
    {
        return $this->belongsTo(TaxRate::class, 'tax_rate_id');
    }

    /** @return BelongsTo<Vendor, $this> */
    public function defaultVendor(): BelongsTo
    {
        return $this->belongsTo(Vendor::class, 'default_vendor_id');
    }

    /** @return HasMany<BranchStock, $this> */
    public function branchStocks(): HasMany
    {
        return $this->hasMany(BranchStock::class, 'product_id');
    }

    protected function casts(): array
    {
        return [
            'type' => ProductType::class,
            'item_type' => ProductItemType::class,
            'sale_price' => MoneyCast::class,
            'cost_price' => MoneyCast::class,
            'min_sale_price' => MoneyCast::class,
            'wholesale_price' => MoneyCast::class,
            'min_sale_qty' => QuantityCast::class,
            'reorder_level' => QuantityCast::class,
            'track_stock' => 'boolean',
            'is_active' => 'boolean',
        ];
    }
}
