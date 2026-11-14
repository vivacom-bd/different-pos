<?php

declare(strict_types=1);

namespace App\Http\Requests\Pos;

use App\Actions\Sales\Data\Discount;
use App\Actions\Sales\Data\SaleDraft;
use App\Actions\Sales\Data\SaleLineDraft;
use App\Actions\Sales\Data\TenderDraft;
use App\Models\User;
use App\Pos\Pricing\PriceCalculator;
use App\Support\Http\ScopedExists;
use App\Support\Money\Money;
use App\Support\Quantity\Quantity;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** The online register's cart. Money in paisa, quantities in milli-units, all integers. */
final class StoreSaleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() instanceof User;
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        $discountTypes = [PriceCalculator::NONE, PriceCalculator::PERCENT_BP, PriceCalculator::AMOUNT];

        return [
            'client_uuid' => ['required', 'uuid'],
            'register_id' => ['nullable', 'integer', ScopedExists::in('registers')->where('branch_id', app(TenantContext::class)->branchId())->where('is_active', true)],
            'customer_id' => ['nullable', 'integer', ScopedExists::in('customers', softDeletes: true)->where('is_walk_in', false)->where('is_active', true)],
            'lines' => ['required', 'array', 'min:1', 'max:300'],
            'lines.*.product_id' => ['required', 'integer', ScopedExists::in('products', softDeletes: true)],
            'lines.*.qty' => ['required', 'integer', 'min:1', 'max:99999999999'],
            'lines.*.unit_price' => ['nullable', 'integer', 'min:0'],
            'lines.*.discount.type' => ['nullable', Rule::in($discountTypes)],
            'lines.*.discount.value' => ['nullable', 'integer', 'min:0'],
            'order_discount.type' => ['nullable', Rule::in($discountTypes)],
            'order_discount.value' => ['nullable', 'integer', 'min:0'],
            'tenders' => ['present', 'array', 'max:10'],
            'tenders.*.payment_method_id' => ['required', 'integer', ScopedExists::in('payment_methods')->where('is_active', true)],
            'tenders.*.amount' => ['required', 'integer', 'min:1'],
            'tenders.*.reference' => ['nullable', 'string', 'max:100'],
            'expected_total' => ['required', 'integer'],
            'approval_token' => ['nullable', 'string', 'max:2000'],
            'member_token' => ['nullable', 'string', 'max:2000'],
            'note' => ['nullable', 'string', 'max:500'],
            'courier_name' => ['nullable', 'string', 'max:100'],
        ];
    }

    public function toDraft(?User $approver, bool $memberVerified = false): SaleDraft
    {
        /** @var list<array<string, mixed>> $lines */
        $lines = $this->validated('lines');
        /** @var list<array<string, mixed>> $tenders */
        $tenders = $this->validated('tenders', []);

        return new SaleDraft(
            lines: array_map(fn (array $line): SaleLineDraft => new SaleLineDraft(
                (int) $line['product_id'],
                Quantity::ofMilli((int) $line['qty']),
                isset($line['unit_price']) ? Money::ofPaisa((int) $line['unit_price']) : null,
                $this->discount(is_array($line['discount'] ?? null) ? $line['discount'] : null),
            ), $lines),
            tenders: array_map(static fn (array $tender): TenderDraft => new TenderDraft(
                (int) $tender['payment_method_id'],
                Money::ofPaisa((int) $tender['amount']),
                isset($tender['reference']) && $tender['reference'] !== '' ? (string) $tender['reference'] : null,
            ), $tenders),
            customerId: $this->filled('customer_id') ? $this->integer('customer_id') : null,
            registerId: $this->filled('register_id') ? $this->integer('register_id') : null,
            orderDiscount: $this->discount(is_array($this->validated('order_discount')) ? $this->validated('order_discount') : null),
            note: $this->filled('note') ? (string) $this->validated('note') : null,
            expectedTotal: Money::ofPaisa($this->integer('expected_total')),
            clientUuid: (string) $this->validated('client_uuid'),
            approver: $approver,
            courierName: $this->filled('courier_name') ? trim((string) $this->validated('courier_name')) : null,
            memberVerified: $memberVerified,
        );
    }

    /** @param  array<string, mixed>|null  $input */
    private function discount(?array $input): Discount
    {
        $type = $input['type'] ?? PriceCalculator::NONE;
        $value = (int) ($input['value'] ?? 0);

        return match ($type) {
            PriceCalculator::PERCENT_BP => Discount::percentBp(min($value, 10_000)),
            PriceCalculator::AMOUNT => Discount::amount(Money::ofPaisa($value)),
            default => Discount::none(),
        };
    }
}
