<?php

declare(strict_types=1);

namespace App\Support\Receipts;

use App\Enums\AllocatableType;
use App\Enums\PaymentDirection;
use App\Enums\SaleStatus;
use App\Models\Branch;
use App\Models\Customer;
use App\Models\Geo\GeoDistrict;
use App\Models\Geo\GeoUpazila;
use App\Models\Payment;
use App\Models\PaymentAllocation;
use App\Models\PaymentMethod;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\SaleReturn;
use App\Models\User;
use App\Support\Cards\CardRenderer;
use App\Support\Customers\CustomerNumber;
use App\Support\Money\Money;
use App\Support\Numbers\Digits;
use App\Support\Qr\QrSvg;
use App\Support\Quantity\Quantity;
use App\Support\Sales\BusinessDay;
use App\Support\Sales\OrderDiscountShares;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonInterface;
use Picqer\Barcode\BarcodeGeneratorSVG;

/**
 * View models for the printed documents: sale receipt (thermal 80/58, A4 tax invoice), return
 * receipt and money receipt. Everything is formatted here (money, quantities, dates in the tenant
 * timezone, Bangla digits for bn), so the Blade views only lay text out.
 *
 * Never includes cost, COGS, batches or customer PIN data. The public variant (/r/{token}) also
 * drops the customer's phone, number and BIN.
 *
 * @phpstan-type Shop array{name: string, logo_url: string|null, legal_name: string|null, vat_bin: string|null, address: string|null, phone: string|null, branch: string, branch_address: string|null, branch_phone: string|null, header: string|null, footer: string|null}
 * @phpstan-type Party array{name: string, shop_name: string|null, customer_no: string|null, phone: string|null, vat_bin: string|null, address: string|null}
 * @phpstan-type Row array{label: string, value: string, strong?: bool}
 */
final readonly class ReceiptBuilder
{
    public function __construct(private TenantContext $context) {}

    /**
     * @return array{kind: 'sale', locale: string, shop: Shop, number: string, date: string, cashier: string|null, courier: string|null, voided: bool, void_reason: string|null, customer: Party|null, lines: list<array{name: string, sku: string, qty: string, unit_price: string, discount: string|null, vat_rate: string|null, total: string}>, totals: list<Row>, payments: list<array{method: string, amount: string, reference: string|null, date: string}>, vat_summary: list<array{rate: string, taxable: string, tax: string}>, barcode: string, qr: string|null, public_url: string|null, exchange: array{return: string, credit: string}|null}
     */
    public function sale(Sale $sale, string $locale, bool $public = false): array
    {
        $sale->loadMissing(['items', 'customer.geoDistrict', 'customer.geoUpazila', 'soldBy']);
        /** @var list<SaleItem> $items */
        $items = $sale->items->sortBy('id')->values()->all();
        $shares = OrderDiscountShares::of($sale, $items);

        $lines = [];
        $vat = [];
        foreach ($items as $item) {
            $lines[] = [
                'name' => $item->product_name,
                'sku' => $item->sku,
                'qty' => $this->qty($item->qty, $locale),
                'unit_price' => $this->money($item->unit_price, $locale, false),
                'discount' => $item->discount->isZero() ? null : $this->money($item->discount, $locale, false),
                'vat_rate' => $item->tax_rate_bp > 0 ? $this->rate($item->tax_rate_bp, $locale) : null,
                'total' => $this->money($item->line_total, $locale, false),
            ];
            $rate = $item->tax_rate_bp;
            $vat[$rate] ??= ['taxable' => Money::zero(), 'tax' => Money::zero()];
            $vat[$rate]['taxable'] = $vat[$rate]['taxable']->plus($item->line_total->minus($shares[$item->id])->minus($item->tax_amount));
            $vat[$rate]['tax'] = $vat[$rate]['tax']->plus($item->tax_amount);
        }
        ksort($vat);

        $totals = [['label' => __('receipts.subtotal'), 'value' => $this->money($sale->subtotal, $locale)]];
        foreach ([
            'item_discounts' => $sale->item_discount_total->negated(),
            'order_discount' => $sale->order_discount->negated(),
            'vat' => $sale->tax_total,
            'shipping' => $sale->shipping,
            'rounding' => $sale->rounding,
        ] as $key => $amount) {
            if (! $amount->isZero()) {
                $totals[] = ['label' => __('receipts.'.$key), 'value' => $this->money($amount, $locale)];
            }
        }
        $totals[] = ['label' => __('receipts.total'), 'value' => $this->money($sale->total, $locale), 'strong' => true];
        $totals[] = ['label' => __('receipts.paid'), 'value' => $this->money($sale->paid, $locale)];
        if (! $sale->change_given->isZero()) {
            $totals[] = ['label' => __('receipts.change'), 'value' => $this->money($sale->change_given, $locale)];
        }
        if (! $sale->returned_total->isZero()) {
            $totals[] = ['label' => __('receipts.returned'), 'value' => $this->money($sale->returned_total, $locale)];
        }
        if (! $sale->due->isZero()) {
            $totals[] = ['label' => __('receipts.due'), 'value' => $this->money($sale->due, $locale), 'strong' => true];
        }

        $exchange = null;
        if ($sale->exchange_return_id !== null) {
            $return = SaleReturn::query()->withoutBranchScope()->find($sale->exchange_return_id);
            if ($return instanceof SaleReturn) {
                $exchange = ['return' => $return->number, 'credit' => $this->money($return->credited, $locale)];
            }
        }

        $url = $sale->public_token !== null ? route('receipts.public', $sale->public_token) : null;

        return [
            'kind' => 'sale',
            'locale' => $locale,
            'shop' => $this->shop($sale->branch_id),
            'number' => (string) $sale->number,
            'date' => $this->date($sale->sold_at, $locale),
            'cashier' => $sale->soldBy?->name,
            'courier' => $sale->courier_name !== null && trim($sale->courier_name) !== '' ? $sale->courier_name : null,
            'voided' => $sale->status === SaleStatus::Voided,
            'void_reason' => $public ? null : $sale->void_reason,
            'customer' => $this->party($sale->customer, $public, $sale->buyer_vat_bin, $locale),
            'lines' => $lines,
            'totals' => $totals,
            'payments' => $this->salePayments($sale, $locale),
            'vat_summary' => array_map(fn (int $rate, array $row): array => [
                'rate' => $this->rate($rate, $locale),
                'taxable' => $this->money($row['taxable'], $locale, false),
                'tax' => $this->money($row['tax'], $locale, false),
            ], array_keys($vat), $vat),
            'barcode' => (new BarcodeGeneratorSVG)->getBarcode((string) $sale->number, BarcodeGeneratorSVG::TYPE_CODE_128, 1.2, 34),
            'qr' => $url !== null ? self::qr($url) : null,
            'public_url' => $url,
            'exchange' => $exchange,
        ];
    }

    /**
     * Delivery chalan: what travels with the goods. Quantities only, deliberately no price, discount,
     * VAT, total or payment. The one money figure is the COD amount, the sale's due, printed only when the sale
     * is not voided, owes something and $cod is true (the print-only toggle).
     *
     * @param  array{name: string, phone: string|null}|null  $courier  resolved by ChalanCourier; null leaves the box blank
     * @return array{kind: 'chalan', locale: string, shop: Shop, number: string, barcode: string, qr: string, invoice_number: string, date: string, voided: bool, void_reason: string|null, customer: array{name: string, shop_name: string|null, phone: string|null, address: string|null}|null, lines: list<array{name: string, sku: string, unit: string, qty: string}>, total_qty: string, has_returns: bool, courier: array{name: string, phone: string|null}|null, cod: string|null}
     */
    public function chalan(Sale $sale, string $locale, ?array $courier = null, bool $cod = true): array
    {
        $sale->loadMissing(['items.product.unit', 'customer.geoDistrict', 'customer.geoUpazila']);
        /** @var list<SaleItem> $items */
        $items = $sale->items->sortBy('id')->values()->all();

        $lines = [];
        $total = Quantity::zero();
        $returns = false;
        foreach ($items as $item) {
            $lines[] = [
                'name' => $item->product_name,
                'sku' => $item->sku,
                'unit' => $item->product?->unit->short_name ?? '',
                'qty' => $this->qty($item->qty, $locale),
            ];
            $total = $total->plus($item->qty);
            $returns = $returns || ! $item->qty_returned->isZero();
        }

        $customer = $sale->customer;
        $party = null;
        if ($customer instanceof Customer && ! $customer->is_walk_in) {
            $address = $this->address($customer, $locale);
            $party = [
                'name' => $customer->name,
                'shop_name' => $customer->shop_name,
                'phone' => $customer->phone,
                'address' => $address,
            ];
        }

        return [
            'kind' => 'chalan',
            'locale' => $locale,
            'shop' => $this->shop($sale->branch_id),
            'number' => 'CH-'.$sale->number,
            'barcode' => CardRenderer::barcode('CH-'.$sale->number),
            'qr' => QrSvg::render('CH-'.$sale->number, quietZone: 0),
            'invoice_number' => (string) $sale->number,
            'date' => $this->date($sale->sold_at, $locale),
            'voided' => $sale->status === SaleStatus::Voided,
            'void_reason' => $sale->void_reason,
            'customer' => $party,
            'lines' => $lines,
            'total_qty' => $this->qty($total, $locale),
            'has_returns' => $returns,
            'courier' => $courier,
            'cod' => $cod && $sale->status !== SaleStatus::Voided && $sale->due->paisa > 0 ? $this->money($sale->due, $locale) : null,
        ];
    }

    /**
     * @return array{kind: 'return', locale: string, shop: Shop, number: string, date: string, cashier: string|null, sale_number: string, customer: Party|null, lines: list<array{name: string, sku: string, qty: string, unit_price: string, total: string, restock: bool}>, totals: list<Row>, mode: string, exchange: array{number: string, total: string, difference: string}|null}
     */
    public function saleReturn(SaleReturn $return, string $locale): array
    {
        $return->loadMissing(['items.saleItem', 'customer', 'sale']);

        $lines = [];
        foreach ($return->items->sortBy('id') as $line) {
            $lines[] = [
                'name' => $line->saleItem->product_name ?? '',
                'sku' => $line->saleItem->sku ?? '',
                'qty' => $this->qty($line->qty, $locale),
                'unit_price' => $this->money($line->unit_price, $locale, false),
                'total' => $this->money($line->line_total, $locale, false),
                'restock' => $line->restock,
            ];
        }

        $totals = [['label' => __('receipts.subtotal'), 'value' => $this->money($return->subtotal, $locale)]];
        if (! $return->tax_total->isZero()) {
            $totals[] = ['label' => __('receipts.vat'), 'value' => $this->money($return->tax_total, $locale)];
        }
        $totals[] = ['label' => __('receipts.total'), 'value' => $this->money($return->total, $locale), 'strong' => true];
        if (! $return->refunded->isZero()) {
            $totals[] = ['label' => __('receipts.refunded'), 'value' => $this->money($return->refunded, $locale)];
        }
        if (! $return->credited->isZero()) {
            $totals[] = ['label' => __('receipts.credited'), 'value' => $this->money($return->credited, $locale)];
        }

        $exchange = null;
        if ($return->exchange_sale_id !== null) {
            $sale = Sale::query()->withoutBranchScope()->find($return->exchange_sale_id);
            if ($sale instanceof Sale) {
                $exchange = [
                    'number' => (string) $sale->number,
                    'total' => $this->money($sale->total, $locale),
                    'difference' => $this->money($sale->total->minus($return->total), $locale),
                ];
            }
        }

        $creator = $return->created_by !== null ? User::query()->find($return->created_by) : null;

        return [
            'kind' => 'return',
            'locale' => $locale,
            'shop' => $this->shop($return->branch_id),
            'number' => $return->number,
            'date' => $this->date($return->returned_at, $locale),
            'cashier' => $creator?->name,
            'sale_number' => (string) $return->sale?->number,
            'customer' => $this->party($return->customer, false, null, $locale),
            'lines' => $lines,
            'totals' => $totals,
            'mode' => (string) __('receipts.refund_modes.'.$return->refund_mode->value),
            'exchange' => $exchange,
        ];
    }

    /**
     * @return array{kind: 'payment', locale: string, shop: Shop, number: string, date: string, received_by: string|null, customer: Party|null, method: string, reference: string|null, amount: string, allocations: list<array{number: string, date: string, amount: string}>, advance: string|null, remaining_due: string, voided: bool}
     */
    public function payment(Payment $payment, string $locale): array
    {
        $payment->loadMissing(['allocations', 'paymentMethod', 'receivedBy']);
        $customer = Customer::query()->withTrashed()->find($payment->party_id);

        $saleIds = $payment->allocations
            ->filter(static fn (PaymentAllocation $a): bool => $a->allocatable_type === AllocatableType::Sale)
            ->pluck('allocatable_id')->all();
        $sales = Sale::query()->withoutBranchScope()->whereIn('id', $saleIds)->get()->keyBy('id');

        $allocated = Money::zero();
        $allocations = [];
        foreach ($payment->allocations->sortBy('id') as $allocation) {
            $sale = $sales->get($allocation->allocatable_id);
            if (! $sale instanceof Sale) {
                continue;
            }
            $allocated = $allocated->plus($allocation->amount);
            $allocations[] = [
                'number' => (string) $sale->number,
                'date' => $this->date($sale->sold_at, $locale, false),
                'amount' => $this->money($allocation->amount, $locale, false),
            ];
        }
        $advance = $payment->amount->minus($allocated);

        $remaining = Money::ofPaisa($customer instanceof Customer ? (int) Sale::query()->withoutBranchScope()
            ->where('branch_id', $payment->branch_id)
            ->where('customer_id', $customer->id)
            ->where('status', SaleStatus::Completed)
            ->sum('due') : 0);

        return [
            'kind' => 'payment',
            'locale' => $locale,
            'shop' => $this->shop($payment->branch_id),
            'number' => $payment->number,
            'date' => $this->date($payment->paid_at, $locale),
            'received_by' => $payment->receivedBy?->name,
            'customer' => $this->party($customer, false, null, $locale),
            'method' => $payment->paymentMethod instanceof PaymentMethod ? $payment->paymentMethod->name : '',
            'reference' => $payment->reference,
            'amount' => $this->money($payment->amount, $locale),
            'allocations' => $allocations,
            'advance' => $advance->paisa > 0 && $payment->direction === PaymentDirection::In ? $this->money($advance, $locale) : null,
            'remaining_due' => $this->money($remaining, $locale),
            'voided' => $payment->voided_at !== null,
        ];
    }

    /** @return list<array{method: string, amount: string, reference: string|null, date: string}> */
    private function salePayments(Sale $sale, string $locale): array
    {
        $allocations = PaymentAllocation::query()
            ->where('allocatable_type', AllocatableType::Sale)
            ->where('allocatable_id', $sale->id)
            ->orderBy('id')
            ->get();
        $payments = Payment::query()->withoutBranchScope()
            ->with('paymentMethod')
            ->whereIn('id', $allocations->pluck('payment_id')->all())
            ->get()
            ->keyBy('id');

        $rows = [];
        foreach ($allocations as $allocation) {
            $payment = $payments->get($allocation->payment_id);
            if (! $payment instanceof Payment || ($payment->voided_at !== null && $sale->status !== SaleStatus::Voided)) {
                continue;
            }
            $rows[] = [
                'method' => $payment->paymentMethod instanceof PaymentMethod ? $payment->paymentMethod->name : '',
                'amount' => $this->money($allocation->amount, $locale),
                'reference' => $payment->reference,
                'date' => $this->date($payment->paid_at, $locale, false),
            ];
        }

        return $rows;
    }

    /** @return Shop */
    private function shop(int $branchId): array
    {
        $tenant = $this->context->tenant();
        $branch = Branch::query()->withTrashed()->find($branchId);

        return [
            'name' => $tenant->name,
            'logo_url' => $tenant->logoUrl(),
            'legal_name' => $tenant->legal_name,
            'vat_bin' => $tenant->vat_bin,
            'address' => $tenant->vat_registered_address ?? $tenant->address,
            'phone' => $tenant->phone,
            'branch' => $branch instanceof Branch ? $branch->name : '',
            'branch_address' => $branch?->address,
            'branch_phone' => $branch?->phone,
            'header' => $branch?->receipt_header,
            'footer' => $branch?->receipt_footer,
        ];
    }

    /** @return Party|null */
    private function party(?Customer $customer, bool $public, ?string $bin, string $locale): ?array
    {
        if (! $customer instanceof Customer || $customer->is_walk_in) {
            return null;
        }

        return [
            'name' => $customer->name,
            'shop_name' => $customer->shop_name,
            'customer_no' => $public || $customer->customer_no === null ? null : CustomerNumber::format((string) $customer->customer_no),
            'phone' => $public ? null : $customer->phone,
            'vat_bin' => $public ? null : ($bin ?? $customer->vat_bin),
            'address' => $public ? null : $this->address($customer, $locale),
        ];
    }

    /** Street, area, upazila, district, post code (what a courier routes by); null when none is filled. */
    private function address(Customer $customer, string $locale): ?string
    {
        $geoName = static fn (GeoDistrict|GeoUpazila|null $geo): ?string => $geo === null ? null : ($locale === 'bn' ? $geo->name_bn : $geo->name_en);
        $address = collect([$customer->address_line, $customer->area, $geoName($customer->geoUpazila), $geoName($customer->geoDistrict), $customer->post_code])
            ->filter(static fn (?string $part): bool => $part !== null && trim($part) !== '')
            ->implode(', ');

        return $address !== '' ? $address : null;
    }

    private function money(Money $money, string $locale, bool $symbol = true): string
    {
        return $money->format($locale, $symbol);
    }

    private function qty(Quantity $qty, string $locale): string
    {
        return $qty->format($locale);
    }

    private function rate(int $basisPoints, string $locale): string
    {
        $whole = intdiv($basisPoints, 100);
        $fraction = $basisPoints % 100;
        $text = $fraction === 0 ? (string) $whole : $whole.'.'.rtrim(str_pad((string) $fraction, 2, '0', STR_PAD_LEFT), '0');

        return $this->digits($text.'%', $locale);
    }

    private function date(CarbonInterface $at, string $locale, bool $time = true): string
    {
        $local = $at->copy()->setTimezone(BusinessDay::timezone($this->context->tenant()));

        return $this->digits($local->format($time ? 'd/m/Y h:i A' : 'd/m/Y'), $locale);
    }

    private function digits(string $text, string $locale): string
    {
        return $locale === 'bn' ? Digits::toBangla($text) : $text;
    }

    private static function qr(string $url): string
    {
        return QrSvg::render($url, quietZone: 1);
    }
}
