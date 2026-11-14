<?php

declare(strict_types=1);

namespace App\Actions\Sales;

use App\Actions\Sales\Data\SaleDraft;
use App\Actions\Sales\Data\SaleLineDraft;
use App\Actions\Sales\Data\TenderDraft;
use App\Enums\AllocatableType;
use App\Enums\CustomerPinPolicy;
use App\Enums\MemberAuth;
use App\Enums\PaymentDirection;
use App\Enums\PaymentMethodType;
use App\Enums\PaymentStatus;
use App\Enums\SaleOrigin;
use App\Enums\SaleStatus;
use App\Enums\StockMovementType;
use App\Events\SaleCompleted;
use App\Exceptions\Domain\BackdateNotAllowed;
use App\Exceptions\Domain\BelowCostSale;
use App\Exceptions\Domain\CreditLimitExceeded;
use App\Exceptions\Domain\CustomerRequired;
use App\Exceptions\Domain\DiscountCapExceeded;
use App\Exceptions\Domain\InvalidSaleDocument;
use App\Exceptions\Domain\InvalidTender;
use App\Exceptions\Domain\MemberPinRequired;
use App\Exceptions\Domain\PriceOverrideNotAllowed;
use App\Models\Customer;
use App\Models\CustomerGroup;
use App\Models\PaymentMethod;
use App\Models\Product;
use App\Models\Register;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\SaleItemBatch;
use App\Models\StockBatch;
use App\Models\TaxRate;
use App\Models\User;
use App\Pos\Pricing\PriceCalculator;
use App\Support\Audit\Audit;
use App\Support\Database\Transaction;
use App\Support\Money\Money;
use App\Support\Numbering\DocumentNumberer;
use App\Support\Quantity\Quantity;
use App\Support\Sales\BusinessDay;
use App\Support\Sales\CustomerAccounts;
use App\Support\Sales\PreparedSale;
use App\Support\Sales\SaleGate;
use App\Support\Stock\StockLedger;
use App\Support\Tenancy\TenantContext;
use Brick\Math\BigInteger;
use Brick\Math\RoundingMode;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Completes a sale in the current branch. The server is the pricing authority: the cart is re-priced
 * with PriceCalculator, a differing client total only sets the `price_mismatch` flag.
 *
 * Checks: role discount cap (pos.discount_override), price override (pos.price_override), below cost
 * (pos.sell_below_cost), back-dating (pos.backdate within tenant backdate_days), tenders, due only
 * for a named customer within the credit limit (manager override: pos.customer_pin_override, the
 * manager-authorisation permission of the POS due flow).
 *
 * Locking order: document_sequences (sale) → branch_stocks → stock_batches → customers.
 * Stored amounts: subtotal = Σ qty × unit_price (VAT included in inclusive mode); sale_items.discount
 * = line + group discount; line_total = qty × unit_price − discount (+ tax when VAT is exclusive);
 * order_discount stays on the header, so total = Σ line_total − order_discount + rounding.
 * Tender payments are numbered `{sale number}/{n}`.
 *
 * @phpstan-import-type CartTotals from PriceCalculator
 */
final readonly class CompleteSale
{
    public function __construct(
        private DocumentNumberer $numberer,
        private StockLedger $ledger,
        private TenantContext $context,
        private SaleGate $gate,
        private CustomerAccounts $accounts,
    ) {}

    public function handle(User $actor, SaleDraft $draft, SaleOrigin $origin = SaleOrigin::Web): Sale
    {
        $this->gate->authorize($actor, 'create', Sale::class);

        if ($draft->clientUuid !== null) {
            $existing = Sale::query()->withoutBranchScope()->where('client_uuid', $draft->clientUuid)->first();
            if ($existing instanceof Sale) {
                return $existing->load('items');
            }
        }

        return Transaction::run(function () use ($actor, $draft, $origin): Sale {
            $prepared = $this->prepare($actor, $draft, $origin);
            $number = $this->numberer->next('sale', $prepared->branch->id);

            $this->ledger->lock($prepared->branch, $prepared->trackedProducts());
            $sale = $this->insertAndIssue($actor, $prepared, $number);

            $customer = $this->accounts->lock($prepared->customer);
            $this->settle($actor, $prepared, $sale, $customer);
            $customer?->save();

            Audit::log('sale.completed', $sale, [
                'number' => $sale->number,
                'total' => $sale->total->paisa,
                'due' => $sale->due->paisa,
                'overrides' => $prepared->overrides,
                'approved_by' => $prepared->overrides === [] ? null : $draft->approver?->id,
                'flags' => $prepared->flags,
            ], $actor);

            DB::afterCommit(static fn () => SaleCompleted::dispatch($sale->id));

            return $sale->load('items');
        });
    }

    /**
     * Reads, validates and prices the cart without writing. $exchangeValue is the return value of an
     * exchange; up to the sale total of it pays the sale as Exchange credit.
     */
    public function prepare(User $actor, SaleDraft $draft, SaleOrigin $origin, ?Money $exchangeValue = null): PreparedSale
    {
        if ($draft->lines === []) {
            throw InvalidSaleDocument::noLines();
        }

        $branch = $this->context->branch();

        $customer = $draft->customerId === null
            ? $this->accounts->walkIn()
            : Customer::query()->findOrFail($draft->customerId);
        $register = $draft->registerId === null ? null : Register::query()->findOrFail($draft->registerId);

        ['products' => $products, 'taxRates' => $taxRates, 'taxInclusive' => $taxInclusive, 'soldAt' => $soldAt, 'priced' => $priced, 'overrides' => $overrides]
            = $this->price($actor, $draft, $customer);

        $total = Money::ofPaisa($priced['order']['grand_total']);
        $exchangeCredit = Money::zero();
        if ($exchangeValue instanceof Money && ! $exchangeValue->isNegative()) {
            $exchangeCredit = $exchangeValue->compareTo($total) < 0 ? $exchangeValue : $total;
        }

        [$tenders, $change] = $this->tenders($draft, $customer, $total->minus($exchangeCredit));

        $paid = $exchangeCredit;
        foreach ($tenders as $tender) {
            $paid = $paid->plus($tender['amount']);
        }
        if ($total->minus($paid)->paisa > 0 && $customer->is_walk_in) {
            throw CreditLimitExceeded::walkIn();
        }

        $flags = [];
        if ($customer->is_walk_in && $exchangeValue === null && (bool) $this->context->tenant()->setting('require_customer_on_sale')) {
            // The online register refuses; a synced offline sale already happened, so it is flagged instead.
            if ($origin === SaleOrigin::Web) {
                throw CustomerRequired::forSale();
            }
            $flags[] = 'customer_missing';
        }
        $memberAuth = $this->memberAuth($actor, $draft, $customer, $origin, $flags);
        $clientTotals = null;
        if ($draft->expectedTotal instanceof Money && ! $draft->expectedTotal->equals($total)) {
            $flags[] = 'price_mismatch';
            $clientTotals = ['total' => $draft->expectedTotal->paisa];
        }

        return new PreparedSale(
            draft: $draft,
            origin: $origin,
            branch: $branch,
            customer: $customer,
            register: $register,
            products: $products,
            taxRates: $taxRates,
            taxInclusive: $taxInclusive,
            priced: $priced,
            soldAt: $soldAt,
            tenders: $tenders,
            change: $change,
            exchangeCredit: $exchangeCredit,
            flags: $flags,
            clientTotals: $clientTotals,
            clientUuid: $draft->clientUuid ?? (string) Str::uuid7(),
            overrides: array_values(array_unique($overrides)),
            memberAuth: $memberAuth,
        );
    }

    /**
     * What the replacement cart of an exchange costs, for the quote the screen shows: the same pricing and
     * rules as a sale, without tenders or writes.
     *
     * @return CartTotals
     */
    public function priceOnly(User $actor, SaleDraft $draft): array
    {
        if ($draft->lines === []) {
            throw InvalidSaleDocument::noLines();
        }
        $customer = $draft->customerId === null
            ? $this->accounts->walkIn()
            : Customer::query()->findOrFail($draft->customerId);

        return $this->price($actor, $draft, $customer)['priced'];
    }

    /**
     * Products, tax, group discount and the rules on them (discount cap, below cost, back-date).
     *
     * @return array{products: list<Product>, taxRates: list<int>, taxInclusive: bool, soldAt: CarbonImmutable, priced: CartTotals, overrides: list<string>}
     */
    private function price(User $actor, SaleDraft $draft, Customer $customer): array
    {
        $branch = $this->context->branch();
        $tenant = $this->context->tenant();
        $approver = $draft->approver;
        $overrides = [];

        $products = $this->products($draft);
        $taxRates = $this->taxRates($products);
        $taxInclusive = (bool) $tenant->setting('tax_inclusive');

        $soldAt = $this->soldAt($actor, $draft, $overrides);

        $groupBp = 0;
        if (! $customer->is_walk_in && $customer->customer_group_id !== null) {
            $group = CustomerGroup::query()->find($customer->customer_group_id);
            $groupBp = $group instanceof CustomerGroup && $group->is_active ? $group->discount_bp : 0;
        }

        $lines = [];
        foreach ($draft->lines as $index => $line) {
            $product = $products[$index];
            if (! $line->qty->isPositive()) {
                throw InvalidSaleDocument::nonPositiveQuantity($index + 1);
            }
            $price = $line->unitPrice ?? $product->sale_price;
            if ($price->isNegative()) {
                throw InvalidSaleDocument::negativeAmount('Line '.($index + 1).' price');
            }
            if (! $price->equals($product->sale_price)) {
                if (! $this->gate->allows($actor, $approver, 'pos.price_override')) {
                    throw PriceOverrideNotAllowed::onLine($index + 1, $product->sku);
                }
                $overrides[] = 'pos.price_override';
            }
            $lines[] = [
                'unit_price' => $price->paisa,
                'qty_milli' => $line->qty->milli,
                'line_discount' => $line->discount->toArray(),
                'tax_rate_bp' => $taxRates[$index],
                'tax_inclusive' => $taxInclusive,
            ];
        }

        $priced = PriceCalculator::calculateCart([
            'lines' => $lines,
            'order_discount' => $draft->orderDiscount->toArray(),
            'group_discount_bp' => $groupBp,
            'rounding_step' => (int) $branch->setting('cash_rounding'),
            'tenders' => [],
        ]);

        $this->checkDiscounts($actor, $approver, $priced, $overrides);
        $this->checkCost($actor, $approver, $draft, $products, $priced, $overrides);

        return ['products' => $products, 'taxRates' => $taxRates, 'taxInclusive' => $taxInclusive, 'soldAt' => $soldAt, 'priced' => $priced, 'overrides' => $overrides];
    }

    /**
     * How the attached member was authenticated (05_Schema sales.member_auth). Walk-in → none. Policy off →
     * no_pin_policy. A member token for this cart → pin_online. A manager with pos.customer_pin_override →
     * override. Otherwise none; under policy `required` a web sale is refused and a synced sale is flagged.
     *
     * @param  list<string>  $flags
     */
    private function memberAuth(User $actor, SaleDraft $draft, Customer $customer, SaleOrigin $origin, array &$flags): MemberAuth
    {
        if ($customer->is_walk_in) {
            return MemberAuth::None;
        }
        $policy = CustomerPinPolicy::fromSetting($this->context->tenant()->setting('customer_pin_policy'));
        if ($policy === CustomerPinPolicy::Off) {
            return MemberAuth::NoPinPolicy;
        }
        if ($draft->memberVerified) {
            return MemberAuth::PinOnline;
        }
        if ($draft->approver instanceof User && $this->gate->allows($actor, $draft->approver, 'pos.customer_pin_override')) {
            return MemberAuth::Override;
        }
        if ($policy === CustomerPinPolicy::Required) {
            if ($origin === SaleOrigin::Web) {
                throw MemberPinRequired::forSale();
            }
            $flags[] = 'member_unverified';
        }

        return MemberAuth::None;
    }

    /**
     * Writes the sale header and lines and takes the stock (FIFO, sale_item_batches, COGS).
     * Call inside Transaction::run() after the number was allocated and the stock rows locked.
     */
    public function insertAndIssue(User $actor, PreparedSale $prepared, string $number, ?int $exchangeReturnId = null): Sale
    {
        $order = $prepared->priced['order'];
        $total = $prepared->total();

        $sale = Sale::query()->create([
            'client_uuid' => $prepared->clientUuid,
            'number' => $number,
            'register_id' => $prepared->register?->id,
            'exchange_return_id' => $exchangeReturnId,
            'customer_id' => $prepared->customer->id,
            'status' => SaleStatus::Completed,
            'sold_at' => $prepared->soldAt,
            'subtotal' => Money::ofPaisa($order['subtotal']),
            'item_discount_total' => Money::ofPaisa($order['line_discount_total'] + $order['group_discount']),
            'order_discount' => Money::ofPaisa($order['order_discount']),
            'tax_total' => Money::ofPaisa($order['tax']),
            'shipping' => Money::zero(),
            'rounding' => Money::ofPaisa($order['rounding']),
            'total' => $total,
            'paid' => Money::zero(),
            'change_given' => $prepared->change,
            'due' => $total,
            'returned_total' => Money::zero(),
            'cogs_total' => Money::zero(),
            'payment_status' => $total->isZero() ? PaymentStatus::Paid : PaymentStatus::Unpaid,
            'courier_name' => $this->courier($prepared->draft->courierName),
            'courier_tracking' => $prepared->draft->courierTracking,
            'public_token' => Str::random(32),
            'buyer_vat_bin' => $prepared->customer->is_walk_in ? null : $prepared->customer->vat_bin,
            'origin' => $prepared->origin,
            'device_created_at' => $prepared->origin === SaleOrigin::Device ? $prepared->soldAt : null,
            'synced_at' => $prepared->origin === SaleOrigin::Device ? now() : null,
            'member_auth' => $prepared->memberAuth,
            'sync_flags' => $prepared->flags === [] ? null : $prepared->flags,
            'client_totals' => $prepared->clientTotals,
            'note' => $prepared->draft->note,
            'sold_by' => $actor->id,
        ]);

        $items = [];
        $issue = [];
        foreach ($prepared->draft->lines as $index => $line) {
            $product = $prepared->products[$index];
            $priced = $prepared->priced['lines'][$index];
            $discount = $priced['line_discount'] + $priced['group_discount'];
            $lineTotal = $priced['gross'] - $discount + ($prepared->taxInclusive ? 0 : $priced['tax']);

            $item = SaleItem::query()->create([
                'sale_id' => $sale->id,
                'product_id' => $product->id,
                'product_name' => $product->name,
                'sku' => $product->sku,
                'qty' => $line->qty,
                'unit_price' => $line->unitPrice ?? $product->sale_price,
                'discount' => Money::ofPaisa($discount),
                'tax_rate_bp' => $prepared->taxRates[$index],
                'tax_amount' => Money::ofPaisa($priced['tax']),
                'line_total' => Money::ofPaisa($lineTotal),
                'cogs' => Money::zero(),
                'qty_returned' => Quantity::zero(),
            ]);
            $items[$index] = $item;
            $issue[] = ['product' => $product, 'qty' => $line->qty, 'ref' => ['type' => 'sale_items', 'id' => $item->id, 'user_id' => $actor->id]];
        }

        $allocations = $this->ledger->issueMany(
            $prepared->branch,
            StockMovementType::Sale,
            $issue,
            $prepared->origin === SaleOrigin::Device ? true : null,
        );

        $cogsTotal = Money::zero();
        $oversold = false;
        foreach ($allocations as $index => $lineAllocations) {
            $cogs = Money::zero();
            foreach ($lineAllocations as $allocation) {
                $cost = $allocation['unit_cost']->times($allocation['qty']);
                $cogs = $cogs->plus($cost);
                if ($allocation['batch'] instanceof StockBatch) {
                    SaleItemBatch::query()->create([
                        'sale_item_id' => $items[$index]->id,
                        'stock_batch_id' => $allocation['batch']->id,
                        'qty' => $allocation['qty'],
                        'unit_cost' => $allocation['unit_cost'],
                        'qty_returned' => Quantity::zero(),
                    ]);
                } else {
                    $oversold = true;
                }
            }
            if (! $cogs->isZero()) {
                $items[$index]->cogs = $cogs;
                $items[$index]->save();
            }
            $cogsTotal = $cogsTotal->plus($cogs);
        }

        $sale->cogs_total = $cogsTotal;
        if ($oversold && $prepared->origin === SaleOrigin::Device) {
            $sale->sync_flags = [...$prepared->flags, 'oversold'];
        }
        $sale->save();

        return $sale;
    }

    /**
     * Records the tenders (and the exchange credit) as payments allocated to the sale, sets paid/due,
     * and moves the customer's balance. $customer is the locked row (null for the walk-in).
     */
    public function settle(User $actor, PreparedSale $prepared, Sale $sale, ?Customer $customer): void
    {
        $credit = $prepared->customerCredit();
        if (! $credit->isZero()) {
            if (! $customer instanceof Customer) {
                throw InvalidTender::creditNeedsCustomer();
            }
            $available = $this->accounts->advance($customer, $sale);
            if ($credit->compareTo($available) > 0) {
                throw InvalidTender::creditExceedsAdvance($credit, $available);
            }
        }

        $party = $customer ?? $prepared->customer;
        $n = 0;
        $payments = [];
        if (! $prepared->exchangeCredit->isZero()) {
            $payments[] = [$this->accounts->systemMethod(PaymentMethodType::ExchangeCredit), $prepared->exchangeCredit, null];
        }
        foreach ($prepared->tenders as $tender) {
            $payments[] = [$tender['method'], $tender['amount'], $tender['reference']];
        }

        $paid = Money::zero();
        foreach ($payments as [$method, $amount, $reference]) {
            $n++;
            $this->accounts->recordPayment(
                $actor,
                $sale->number.'/'.$n,
                PaymentDirection::In,
                $party,
                $method,
                $amount,
                $prepared->soldAt,
                [[AllocatableType::Sale, $sale->id, $amount]],
                $reference,
                $prepared->register?->id,
            );
            $paid = $paid->plus($amount);
        }

        $due = $sale->total->minus($paid);
        $sale->paid = $paid;
        $sale->due = $due;
        $sale->payment_status = match (true) {
            $due->isZero() => PaymentStatus::Paid,
            $paid->isZero() => PaymentStatus::Unpaid,
            default => PaymentStatus::Partial,
        };
        $sale->save();

        if (! $customer instanceof Customer) {
            return;
        }

        // Receivable grows by what was not paid with money: the due plus credit spent.
        $customer->balance = $customer->balance->plus($sale->total)->minus($prepared->moneyReceived());
        if ($customer->last_purchase_at === null || $customer->last_purchase_at->lt($prepared->soldAt)) {
            $customer->last_purchase_at = Carbon::instance($prepared->soldAt);
        }

        if ($due->paisa > 0) {
            $limit = $this->accounts->creditLimit($customer);
            if ($limit instanceof Money && $customer->balance->compareTo($limit) > 0) {
                if (! $this->gate->allows($actor, $prepared->draft->approver, 'pos.customer_pin_override')) {
                    throw CreditLimitExceeded::over($limit, $customer->balance);
                }
                Audit::log('sale.credit_limit_override', $sale, [
                    'limit' => $limit->paisa,
                    'balance' => $customer->balance->paisa,
                    'approved_by' => $prepared->draft->approver?->id,
                ], $actor);
            }
        }
    }

    /** Courier text as typed: trimmed, blank = none, cut to the column so a synced sale is never rejected for it. */
    private function courier(?string $name): ?string
    {
        $name = trim((string) $name);

        return $name === '' ? null : mb_substr($name, 0, 100);
    }

    /** @return list<Product> one per draft line */
    private function products(SaleDraft $draft): array
    {
        $ids = array_values(array_unique(array_map(static fn (SaleLineDraft $line): int => $line->productId, $draft->lines)));
        $found = [];
        foreach (Product::query()->whereIn('id', $ids)->get() as $product) {
            $found[$product->id] = $product;
        }

        $missing = array_values(array_diff($ids, array_keys($found)));
        if ($missing !== []) {
            throw (new ModelNotFoundException)->setModel(Product::class, $missing);
        }
        // Raw materials are never sold, whatever a (possibly stale or tampered) client sends; this also covers the exchange's replacement sale.
        foreach ($found as $product) {
            if ($product->isRawMaterial()) {
                throw InvalidSaleDocument::rawMaterial($product->name);
            }
        }

        return array_map(static fn (SaleLineDraft $line): Product => $found[$line->productId], $draft->lines);
    }

    /**
     * @param  list<Product>  $products
     * @return list<int>
     */
    private function taxRates(array $products): array
    {
        $ids = array_values(array_unique(array_filter(array_map(static fn (Product $product): ?int => $product->tax_rate_id, $products))));
        $rates = $ids === [] ? [] : TaxRate::query()->whereIn('id', $ids)->pluck('rate_bp', 'id')->all();

        return array_map(static fn (Product $product): int => $product->tax_rate_id === null ? 0 : (int) ($rates[$product->tax_rate_id] ?? 0), $products);
    }

    /** @param  list<string>  $overrides */
    private function soldAt(User $actor, SaleDraft $draft, array &$overrides): CarbonImmutable
    {
        $now = CarbonImmutable::now();
        if (! $draft->soldAt instanceof CarbonInterface) {
            return $now;
        }

        $soldAt = CarbonImmutable::instance($draft->soldAt);
        if ($soldAt->gt($now->addMinutes(5))) {
            throw BackdateNotAllowed::future();
        }

        $days = BusinessDay::daysBetween($soldAt, $now, BusinessDay::timezone($this->context->tenant()));
        if ($days > 0) {
            if (! $this->gate->allows($actor, $draft->approver, 'pos.backdate')) {
                throw BackdateNotAllowed::noPermission();
            }
            $limit = (int) $this->context->tenant()->setting('backdate_days');
            if ($days > $limit) {
                throw BackdateNotAllowed::tooOld($limit);
            }
            $overrides[] = 'pos.backdate';
        }

        return $soldAt;
    }

    /**
     * Manual discounts (line discount + the line's share of the order discount) against the role cap.
     * The customer-group discount is a price rule, not a manual discount, so it does not count.
     *
     * @param  array{lines: list<array<string, int>>, order: array<string, int>}  $priced
     * @param  list<string>  $overrides
     */
    private function checkDiscounts(User $actor, ?User $approver, array $priced, array &$overrides): void
    {
        $cap = $this->gate->discountCapBp($actor, $approver);
        $override = null;

        foreach ($priced['lines'] as $index => $line) {
            $gross = $line['gross'];
            $lineOnly = $line['line_discount'];
            $manual = $lineOnly + $line['order_discount'];
            if ($manual === 0 || $gross === 0) {
                continue;
            }
            if (! self::exceeds($manual, $gross, $cap)) {
                continue;
            }

            $override ??= $this->gate->allows($actor, $approver, 'pos.discount_override');
            if (! $override) {
                throw self::exceeds($lineOnly, $gross, $cap)
                    ? DiscountCapExceeded::onLine($index + 1, self::bp($lineOnly, $gross), $cap)
                    : DiscountCapExceeded::onOrder(self::bp($manual, $gross), $cap);
            }
            $overrides[] = 'pos.discount_override';
        }
    }

    /**
     * @param  list<Product>  $products
     * @param  array{lines: list<array<string, int>>, order: array<string, int>}  $priced
     * @param  list<string>  $overrides
     */
    private function checkCost(User $actor, ?User $approver, SaleDraft $draft, array $products, array $priced, array &$overrides): void
    {
        $allowed = null;
        foreach ($priced['lines'] as $index => $line) {
            $product = $products[$index];
            $cost = $product->cost_price->times($draft->lines[$index]->qty);
            if ($cost->isZero() || $line['net'] >= $cost->paisa) {
                continue;
            }

            $allowed ??= $this->gate->allows($actor, $approver, 'pos.sell_below_cost');
            if (! $allowed) {
                throw BelowCostSale::onLine($index + 1, $product->sku);
            }
            $overrides[] = 'pos.sell_below_cost';
        }
    }

    /**
     * Validates the tenders and turns them into applied amounts: the excess over $toPay is change,
     * which only cash can give (taken from the last cash tender backwards).
     *
     * @return array{0: list<array{method: PaymentMethod, amount: Money, reference: string|null}>, 1: Money}
     */
    private function tenders(SaleDraft $draft, Customer $customer, Money $toPay): array
    {
        $ids = array_values(array_unique(array_map(static fn (TenderDraft $tender): int => $tender->paymentMethodId, $draft->tenders)));
        $methods = [];
        foreach (PaymentMethod::query()->whereIn('id', $ids)->get() as $method) {
            $methods[$method->id] = $method;
        }

        $applied = [];
        $tendered = Money::zero();
        $cash = Money::zero();
        foreach ($draft->tenders as $index => $tender) {
            $method = $methods[$tender->paymentMethodId]
                ?? throw (new ModelNotFoundException)->setModel(PaymentMethod::class, [$tender->paymentMethodId]);
            if ($tender->amount->paisa <= 0) {
                throw InvalidTender::nonPositive($index + 1);
            }
            if (! $method->is_active) {
                throw InvalidTender::inactive($method->name);
            }
            if ($method->type === PaymentMethodType::ExchangeCredit) {
                throw InvalidTender::exchangeCreditOutsideExchange();
            }
            if ($method->type === PaymentMethodType::CustomerCredit && $customer->is_walk_in) {
                throw InvalidTender::creditNeedsCustomer();
            }
            if ($method->requires_reference && trim((string) $tender->reference) === '') {
                throw InvalidTender::referenceRequired($method->name);
            }
            if ($method->type === PaymentMethodType::Cash) {
                $cash = $cash->plus($tender->amount);
            }
            $tendered = $tendered->plus($tender->amount);
            $applied[] = ['method' => $method, 'amount' => $tender->amount, 'reference' => $tender->reference];
        }

        $change = $tendered->minus($toPay);
        if ($change->paisa <= 0) {
            return [$applied, Money::zero()];
        }
        if ($change->compareTo($cash) > 0) {
            throw InvalidTender::changeFromNonCash($change);
        }

        $left = $change;
        $result = [];
        foreach (array_reverse($applied) as $tender) {
            if ($tender['method']->type === PaymentMethodType::Cash && ! $left->isZero()) {
                $take = $tender['amount']->compareTo($left) < 0 ? $tender['amount'] : $left;
                $left = $left->minus($take);
                $tender = ['method' => $tender['method'], 'amount' => $tender['amount']->minus($take), 'reference' => $tender['reference']];
            }
            if (! $tender['amount']->isZero()) {
                array_unshift($result, $tender);
            }
        }

        return [$result, $change];
    }

    /** $part / $whole > $capBp / 10000, exactly. */
    private static function exceeds(int $part, int $whole, int $capBp): bool
    {
        return BigInteger::of($part)->multipliedBy(10_000)->isGreaterThan(BigInteger::of($whole)->multipliedBy($capBp));
    }

    private static function bp(int $part, int $whole): int
    {
        return BigInteger::of($part)->multipliedBy(10_000)->dividedBy($whole, RoundingMode::HalfUp)->toInt();
    }
}
