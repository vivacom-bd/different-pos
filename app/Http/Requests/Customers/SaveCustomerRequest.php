<?php

declare(strict_types=1);

namespace App\Http\Requests\Customers;

use App\Models\User;
use App\Support\Geo\GeoCatalog;
use App\Support\Money\Money;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/** Create / edit customer. The controller authorizes; the group must belong to the caller's tenant. */
final class SaveCustomerRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() instanceof User;
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        $user = $this->user();
        $tenantId = $user instanceof User ? $user->tenant_id : null;

        return [
            'name' => ['required', 'string', 'max:150'],
            'name_bn' => ['nullable', 'string', 'max:150'],
            'shop_name' => ['nullable', 'string', 'max:150'],
            'phone' => ['required', 'string', 'max:30'],
            'alt_phone' => ['nullable', 'string', 'max:30'],
            'email' => ['nullable', 'email', 'max:150'],
            'customer_group_id' => ['nullable', 'integer', Rule::exists('customer_groups', 'id')->where('tenant_id', $tenantId)->whereNull('deleted_at')],
            'credit_limit' => ['nullable', 'integer', 'min:0', 'max:9000000000000'],
            'geo_division_id' => ['nullable', 'integer', Rule::exists('geo_divisions', 'id')],
            'geo_district_id' => ['nullable', 'integer', Rule::exists('geo_districts', 'id')],
            'geo_upazila_id' => ['nullable', 'integer', Rule::exists('geo_upazilas', 'id')],
            'geo_union_id' => ['nullable', 'integer', Rule::exists('geo_unions', 'id')],
            'address_line' => ['nullable', 'string', 'max:255'],
            'area' => ['nullable', 'string', 'max:100'],
            'post_code' => ['nullable', 'string', 'max:10'],
            'dob' => ['nullable', 'date', 'before_or_equal:today'],
            'anniversary_date' => ['nullable', 'date'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ];
    }

    /**
     * The chosen location must be one chain: district in the division, upazila in the district, union in the upazila.
     *
     * @return list<callable(Validator): void>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                $ids = [];
                foreach (['geo_division_id', 'geo_district_id', 'geo_upazila_id', 'geo_union_id'] as $key) {
                    $value = $validator->getValue($key);
                    $ids[$key] = $value === null || $value === '' ? null : (int) $value;
                }
                if ($ids === array_fill_keys(array_keys($ids), null) || $validator->errors()->hasAny(array_keys($ids))) {
                    return;
                }
                $chain = app(GeoCatalog::class)->resolve($ids['geo_division_id'], $ids['geo_district_id'], $ids['geo_upazila_id'], $ids['geo_union_id']);
                if ($chain === null) {
                    $validator->errors()->add('geo_upazila_id', __('The chosen district, upazila and union do not belong together.'));
                }
            },
        ];
    }

    /**
     * The validated fields in the shape SaveCustomer expects (credit limit: paisa to Money).
     *
     * @return array<string, mixed>
     */
    public function customerData(): array
    {
        /** @var array<string, mixed> $data */
        $data = $this->validated();
        if (array_key_exists('credit_limit', $data)) {
            $data['credit_limit'] = $data['credit_limit'] === null ? null : Money::ofPaisa((int) $data['credit_limit']);
        }
        foreach (['geo_division_id', 'geo_district_id', 'geo_upazila_id', 'geo_union_id'] as $key) {
            if (array_key_exists($key, $data)) {
                $data[$key] = $data[$key] === null || $data[$key] === '' ? null : (int) $data[$key];
            }
        }

        return $data;
    }
}
