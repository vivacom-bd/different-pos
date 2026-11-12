<?php

declare(strict_types=1);

namespace App\Http\Requests\Purchasing;

use App\Enums\PaymentMethodType;
use App\Models\Purchase;
use App\Models\User;
use App\Support\Http\ScopedExists;
use Illuminate\Foundation\Http\FormRequest;

/** Quantities are milli-units, money is paisa. */
final class ReceivePurchaseRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();

        return $user instanceof User && $user->can('create', Purchase::class) && $user->can('purchases.receive');
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return [
            'vendor_id' => ['nullable', 'integer', ScopedExists::in('vendors', softDeletes: true)],
            'vendor_ref' => ['nullable', 'string', 'max:120'],
            'purchased_at' => ['nullable', 'date'],
            'discount' => ['nullable', 'integer', 'min:0'],
            'shipping' => ['nullable', 'integer', 'min:0'],
            'note' => ['nullable', 'string', 'max:1000'],
            'items' => ['required', 'array', 'min:1', 'max:300'],
            'items.*.product_id' => ['required', 'integer', ScopedExists::in('products', softDeletes: true)],
            'items.*.qty' => ['required', 'integer', 'min:1'],
            'items.*.unit_cost' => ['required', 'integer', 'min:0'],
            'items.*.discount' => ['nullable', 'integer', 'min:0'],
            'items.*.batch_no' => ['nullable', 'string', 'max:64'],
            'items.*.expires_at' => ['nullable', 'date'],
            'payment' => ['nullable', 'array'],
            'payment.amount' => ['required_with:payment', 'integer', 'min:1'],
            'payment.payment_method_id' => ['required_with:payment', 'integer', ScopedExists::in('payment_methods')->whereNotIn('type', PaymentMethodType::storeCreditValues())],
            'payment.reference' => ['nullable', 'string', 'max:120'],
        ];
    }
}
