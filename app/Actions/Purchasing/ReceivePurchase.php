<?php

declare(strict_types=1);

namespace App\Actions\Purchasing;

use App\Enums\PaymentStatus;
use App\Enums\PurchaseStatus;
use App\Enums\StockMovementType;
use App\Exceptions\Domain\DuplicateVendorBill;
use App\Exceptions\Domain\InvalidStockDocument;
use App\Models\PaymentMethod;
use App\Models\Product;
use App\Models\Purchase;
use App\Models\PurchaseItem;
use App\Models\User;
use App\Models\Vendor;
use App\Support\Database\Transaction;
use App\Support\Money\Money;
use App\Support\Numbering\DocumentNumberer;
use App\Support\Quantity\Quantity;
use App\Support\Stock\StockLedger;
use App\Support\Tenancy\BranchScope;
use App\Support\Tenancy\TenantContext;
use Brick\Math\BigInteger;
use Brick\Math\RoundingMode;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\ModelNotFoundException;

/**
 * Records a received purchase in the current branch: purchase + items, one FIFO batch per stocked
 * line, vendor balance += total, optional immediate payment.
 * A vendorless purchase (vendor_id null) books no payable and touches no vendor balance: it is settled on receipt
 * (paid = total, due 0, no payment row), and a part payment (due > 0) is refused.
 *
 * Amounts: line net = unit_cost × qty − line discount; subtotal = Σ line net; tax_total = Σ line tax;
 * total = subtotal − discount + tax_total + shipping. Shipping minus the order discount is prorated
 * over the lines by net amount, so purchase_items.line_total is the landed line amount (Σ = total)
 * and the batch unit cost is line_total ÷ qty (half-up). products.cost_price takes the landed cost.
 *
 * @phpstan-type PurchaseLineInput array{product_id: int, qty: Quantity, unit_cost: Money, discount?: Money, tax_amount?: Money, batch_no?: string|null, expires_at?: CarbonInterface|null}
 * @phpstan-type PurchasePaymentInput array{amount: Money, payment_method_id: int, reference?: string|null}
 * @phpstan-type PurchaseInput array{vendor_id?: int|null, items: list<PurchaseLineInput>, vendor_ref?: string|null, purchased_at?: CarbonInterface|null, discount?: Money, shipping?: Money, note?: string|null, payment?: PurchasePaymentInput|null}
 */
final readonly class ReceivePurchase
{
    public function __construct(
        private DocumentNumberer $numberer,
        private StockLedger $ledger,
        private PayVendor $payVendor,
        private TenantContext $context,
    ) {}

    /** @param  PurchaseInput  $input */
    public function handle(User $actor, array $input): Purchase
    {
        $lines = $input['items'];
        if ($lines === []) {
            throw InvalidStockDocument::noLines();
        }

        $orderDiscount = $input['discount'] ?? Money::zero();
        $shipping = $input['shipping'] ?? Money::zero();
        if ($orderDiscount->isNegative()) {
            throw InvalidStockDocument::negativeAmount('discount');
        }
        if ($shipping->isNegative()) {
            throw InvalidStockDocument::negativeAmount('shipping');
        }

        $nets = [];
        $taxes = [];
        foreach ($lines as $index => $line) {
            $number = $index + 1;
            if (! $line['qty']->isPositive()) {
                throw InvalidStockDocument::nonPositiveQuantity($number);
            }
            $discount = $line['discount'] ?? Money::zero();
            $tax = $line['tax_amount'] ?? Money::zero();
            if ($line['unit_cost']->isNegative() || $discount->isNegative() || $tax->isNegative()) {
                throw InvalidStockDocument::negativeAmount('line '.$number);
            }
            $net = $line['unit_cost']->times($line['qty'])->minus($discount);
            if ($net->isNegative()) {
                throw InvalidStockDocument::negativeAmount('line '.$number.' after discount');
            }
            $nets[] = $net;
            $taxes[] = $tax;
        }

        $subtotal = array_reduce($nets, static fn (Money $sum, Money $net): Money => $sum->plus($net), Money::zero());
        $taxTotal = array_reduce($taxes, static fn (Money $sum, Money $tax): Money => $sum->plus($tax), Money::zero());
        $total = $subtotal->minus($orderDiscount)->plus($taxTotal)->plus($shipping);
        if ($total->isNegative()) {
            throw InvalidStockDocument::negativeTotal();
        }

        $payment = $input['payment'] ?? null;
        if ($payment !== null) {
            if ($payment['amount']->paisa <= 0) {
                throw InvalidStockDocument::nonPositivePayment();
            }
            if ($payment['amount']->compareTo($total) > 0) {
                throw InvalidStockDocument::overpaid('(new)');
            }
        }

        $vendorless = ($input['vendor_id'] ?? null) === null;
        if ($vendorless && $payment !== null && $payment['amount']->compareTo($total) < 0) {
            throw InvalidStockDocument::vendorlessDue();
        }

        // Landed amounts: line net + tax + its share of (shipping − order discount).
        $weights = array_map(static fn (Money $net): int => $net->paisa, $nets);
        if (array_sum($weights) === 0) {
            $weights = array_fill(0, count($nets), 1);
        }
        $shares = $shipping->minus($orderDiscount)->allocate($weights);

        return Transaction::run(function () use ($actor, $input, $lines, $nets, $taxes, $shares, $subtotal, $taxTotal, $total, $orderDiscount, $shipping, $payment, $vendorless): Purchase {
            $branch = $this->context->branch();
            $vendorRef = trim((string) ($input['vendor_ref'] ?? ''));
            $vendorRef = $vendorRef === '' ? null : $vendorRef;
            $vendor = ($input['vendor_id'] ?? null) === null ? null : Vendor::query()->findOrFail($input['vendor_id']);
            $method = $payment === null ? null : PaymentMethod::query()->findOrFail($payment['payment_method_id']);
            if ($method instanceof PaymentMethod && $method->type->isStoreCredit()) {
                throw InvalidStockDocument::storeCreditPayment();
            }

            $ids = array_values(array_unique(array_map(static fn (array $line): int => $line['product_id'], $lines)));
            $products = self::products($ids);

            // Locking order: document_sequences → branch_stocks → stock_batches → vendors.
            $number = $this->numberer->next('purchase', $branch->id);
            $paymentNumber = $payment === null || $vendorless ? null : $this->numberer->next('payment', $branch->id);

            $purchase = Purchase::query()->create([
                'vendor_id' => $vendor?->id,
                'number' => $number,
                'vendor_ref' => $vendorRef,
                'status' => PurchaseStatus::Received,
                'purchased_at' => $input['purchased_at'] ?? now(),
                'received_at' => now(),
                'subtotal' => $subtotal,
                'discount' => $orderDiscount,
                'tax_total' => $taxTotal,
                'shipping' => $shipping,
                'total' => $total,
                'paid' => $vendorless ? $total : Money::zero(),
                'due' => $vendorless ? Money::zero() : $total,
                'payment_status' => $vendorless || $total->isZero() ? PaymentStatus::Paid : PaymentStatus::Unpaid,
                'note' => $input['note'] ?? null,
                'created_by' => $actor->id,
                'updated_by' => $actor->id,
            ]);

            $this->ledger->lock($branch, array_values(array_filter($products, static fn (Product $product): bool => $product->track_stock)));

            foreach ($lines as $index => $line) {
                $product = $products[$line['product_id']];
                $landed = $nets[$index]->plus($taxes[$index])->plus($shares[$index]);

                $item = PurchaseItem::query()->create([
                    'purchase_id' => $purchase->id,
                    'product_id' => $product->id,
                    'qty' => $line['qty'],
                    'unit_cost' => $line['unit_cost'],
                    'discount' => $line['discount'] ?? Money::zero(),
                    'tax_amount' => $taxes[$index],
                    'line_total' => $landed,
                ]);

                if (! $product->track_stock) {
                    continue;
                }

                $unitCost = self::perUnit($landed, $line['qty']);
                $this->ledger->receive(
                    $product,
                    $branch,
                    $line['qty'],
                    $unitCost,
                    StockMovementType::Purchase,
                    $line['batch_no'] ?? null,
                    ['type' => 'purchase_items', 'id' => $item->id, 'user_id' => $actor->id],
                    expiresAt: $line['expires_at'] ?? null,
                );

                $product->cost_price = $unitCost;
                $product->save();
            }

            if (! $vendor instanceof Vendor) {
                return $purchase->refresh();
            }

            $vendor = Vendor::query()->whereKey($vendor->id)->lockForUpdate()->firstOrFail();
            $this->assertBillNumberFree($vendor, $vendorRef, $purchase->id);
            $vendor->balance = $vendor->balance->plus($total);
            $vendor->save();

            if ($payment !== null && $method instanceof PaymentMethod && $paymentNumber !== null) {
                $this->payVendor->record(
                    $actor,
                    $vendor,
                    $paymentNumber,
                    $payment['amount'],
                    $method,
                    [['purchase_id' => $purchase->id, 'amount' => $payment['amount']]],
                    reference: $payment['reference'] ?? null,
                );
            }

            return $purchase->refresh();
        });
    }

    /**
     * One live bill number per vendor (any branch of the tenant), compared trimmed and case-insensitively.
     * Called right after the vendor row lock, so concurrent saves for the vendor serialise; the locking
     * read sees rows committed by the save that held the lock first. Cancelled and deleted bills do not count,
     * and legacy duplicates are left alone.
     */
    private function assertBillNumberFree(Vendor $vendor, ?string $ref, int $exceptId): void
    {
        if ($ref === null) {
            return;
        }

        $existing = Purchase::query()
            ->withoutGlobalScope(BranchScope::class)
            ->where('vendor_id', $vendor->id)
            ->where('status', '!=', PurchaseStatus::Cancelled->value)
            ->whereKeyNot($exceptId)
            ->whereRaw('LOWER(TRIM(vendor_ref)) = ?', [mb_strtolower($ref)])
            ->orderBy('id')
            ->lockForUpdate()
            ->first();

        if ($existing instanceof Purchase) {
            throw new DuplicateVendorBill($ref, $existing->number);
        }
    }

    /** round_half_up(amount ÷ qty) in paisa per unit. */
    private static function perUnit(Money $amount, Quantity $qty): Money
    {
        return Money::ofPaisa(
            BigInteger::of($amount->paisa)->multipliedBy(Quantity::SCALE_FACTOR)->dividedBy($qty->milli, RoundingMode::HalfUp)->toInt(),
        );
    }

    /**
     * @param  list<int>  $ids
     * @return array<int, Product>
     */
    private static function products(array $ids): array
    {
        $products = [];
        foreach (Product::query()->whereIn('id', $ids)->get() as $product) {
            $products[$product->id] = $product;
        }

        $missing = array_values(array_diff($ids, array_keys($products)));
        if ($missing !== []) {
            throw (new ModelNotFoundException)->setModel(Product::class, $missing);
        }

        return $products;
    }
}
