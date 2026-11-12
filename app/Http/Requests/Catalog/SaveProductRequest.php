<?php

declare(strict_types=1);

namespace App\Http\Requests\Catalog;

use App\Enums\ProductItemType;
use App\Models\Product;
use App\Models\User;
use App\Support\Http\ScopedExists;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** Create and update share the rules; money arrives as integer paisa, quantities as milli-units. */
final class SaveProductRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();
        $product = $this->route('product');

        return $user instanceof User && ($product instanceof Product
            ? $user->can('update', $product)
            : $user->can('create', Product::class));
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'track_stock' => $this->boolean('track_stock', true),
            'is_active' => $this->boolean('is_active', true),
            'remove_image' => $this->boolean('remove_image'),
            'item_type' => $this->input('item_type') ?: ProductItemType::Finished->value,
        ]);
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        $creating = ! $this->route('product') instanceof Product;
        $raw = $this->input('item_type') === ProductItemType::RawMaterial->value;

        // Opening stock: create-only, simple products (no variant grid), and only for users who may adjust stock.
        $canOpen = $creating && $this->user()?->can('stock.adjust') === true && ! $this->filled('variants');

        return [
            'opening_qty' => $canOpen ? ['nullable', 'integer', 'min:0', 'max:99999999999'] : ['prohibited'],
            'name' => ['required', 'string', 'max:190'],
            'name_bn' => ['nullable', 'string', 'max:190'],
            'sku' => ['required', 'string', 'max:64'],
            'barcode' => ['nullable', 'string', 'max:64'],
            'hs_code' => ['nullable', 'string', 'max:32'],
            'type' => ['required', Rule::in(['standard', 'service'])],
            'item_type' => ['required', Rule::enum(ProductItemType::class)],
            'category_id' => ['nullable', 'integer', ScopedExists::in('categories', softDeletes: true)],
            'brand_id' => ['nullable', 'integer', ScopedExists::in('brands', softDeletes: true)],
            'unit_id' => ['required', 'integer', ScopedExists::in('units')],
            'tax_rate_id' => ['nullable', 'integer', ScopedExists::in('tax_rates')],
            'default_vendor_id' => ['nullable', 'integer', ScopedExists::in('vendors', softDeletes: true)],
            // A raw material has no retail price (stored as 0).
            'sale_price' => [$raw ? 'nullable' : 'required', 'integer', 'min:0'],
            'cost_price' => ['nullable', 'integer', 'min:0'],
            'min_sale_price' => ['nullable', 'integer', 'min:0'],
            'wholesale_price' => ['nullable', 'integer', 'min:0'],
            'min_sale_qty' => ['nullable', 'integer', 'min:0'],
            'reorder_level' => ['nullable', 'integer', 'min:0'],
            'track_stock' => ['boolean'],
            'is_active' => ['boolean'],
            'description' => ['nullable', 'string', 'max:2000'],
            'image' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:4096'],
            'remove_image' => ['boolean'],
            // Reuse a photo already on one of this tenant's products (the image picker); no file copy.
            'existing_image' => ['nullable', 'string', 'max:255', ScopedExists::in('products', 'image_path', softDeletes: true)],
            'variants' => [$creating && ! $raw ? 'nullable' : 'prohibited', 'array'],
            'variants.option1_id' => ['required_with:variants', 'integer', ScopedExists::in('variant_options')],
            'variants.option2_id' => ['nullable', 'integer', 'different:variants.option1_id', ScopedExists::in('variant_options')],
            'variants.rows' => ['required_with:variants', 'array', 'min:1', 'max:200'],
            'variants.rows.*.option1_value_id' => ['required', 'integer', ScopedExists::in('variant_option_values')],
            'variants.rows.*.option2_value_id' => ['nullable', 'integer', ScopedExists::in('variant_option_values')],
            'variants.rows.*.sale_price' => ['nullable', 'integer', 'min:0'],
            'variants.rows.*.cost_price' => ['nullable', 'integer', 'min:0'],
            'variants.rows.*.sku' => ['nullable', 'string', 'max:64'],
            'variants.rows.*.barcode' => ['nullable', 'string', 'max:64'],
        ];
    }
}
