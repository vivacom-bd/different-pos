<?php

declare(strict_types=1);

use App\Actions\Sales\CompleteSale;
use App\Actions\Sales\Data\Discount;
use App\Actions\Sales\Data\SaleDraft;
use App\Actions\Sales\Data\TenderDraft;
use App\Enums\AllocatableType;
use App\Enums\PartyType;
use App\Enums\PaymentDirection;
use App\Enums\PaymentStatus;
use App\Enums\SaleOrigin;
use App\Enums\SaleStatus;
use App\Enums\StockMovementType;
use App\Exceptions\Domain\BackdateNotAllowed;
use App\Exceptions\Domain\BelowCostSale;
use App\Exceptions\Domain\CreditLimitExceeded;
use App\Exceptions\Domain\DiscountCapExceeded;
use App\Exceptions\Domain\InsufficientStock;
use App\Exceptions\Domain\InvalidSaleDocument;
use App\Exceptions\Domain\InvalidTender;
use App\Exceptions\Domain\PriceOverrideNotAllowed;
use App\Models\BranchStock;
use App\Models\Customer;
use App\Models\CustomerGroup;
use App\Models\Payment;
use App\Models\PaymentAllocation;
use App\Models\PaymentMethod;
use App\Models\Product;
use App\Models\Register;
use App\Models\Sale;
use App\Models\SaleItemBatch;
use App\Models\StockMovement;
use App\Models\TaxRate;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Money\Money;
use App\Support\Tenancy\TenantContext;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Spatie\Permission\Models\Role;
use Tests\Fixtures\SalesWorld;

beforeEach(function (): void {
    $this->travelTo(now()->setTimezone('Asia/Dhaka')->setDate(2026, 11, 3)->setTime(14, 0)->utc());
    $this->world = SalesWorld::make();
    $this->shirt = $this->world->product(150_000, 60_000);
    $this->world->stock($this->shirt, 10, 60_000);
    $this->onHand = fn (Product $product): string => (string) BranchStock::query()->where('product_id', $product->id)->first()?->qty_on_hand->toDecimalString();
});

afterEach(function (): void {
    app(TenantContext::class)->forget();
});

it('completes a cash sale with change, a payment, stock and COGS', function (): void {
    $sale = $this->world->sell([$this->world->line($this->shirt, 2)], [$this->world->tender('cash', 400_000)]);

    expect($sale->number)->toBe('DF1-SALE-26000001')
        ->and($sale->status)->toBe(SaleStatus::Completed)
        ->and($sale->origin)->toBe(SaleOrigin::Web)
        ->and($sale->customer_id)->toBe($this->world->walkIn->id)
        ->and($sale->subtotal->paisa)->toBe(300_000)
        ->and($sale->total->paisa)->toBe(300_000)
        ->and($sale->paid->paisa)->toBe(300_000)
        ->and($sale->change_given->paisa)->toBe(100_000)
        ->and($sale->due->paisa)->toBe(0)
        ->and($sale->payment_status)->toBe(PaymentStatus::Paid)
        ->and($sale->cogs_total->paisa)->toBe(120_000)
        ->and($sale->public_token)->toHaveLength(32)
        ->and($sale->sync_flags)->toBeNull()
        ->and($sale->items)->toHaveCount(1)
        ->and($sale->items[0]->line_total->paisa)->toBe(300_000)
        ->and($sale->items[0]->cogs->paisa)->toBe(120_000)
        ->and(($this->onHand)($this->shirt))->toBe('8.000');

    $payment = Payment::query()->sole();
    expect($payment->number)->toBe('DF1-SALE-26000001/1')
        ->and($payment->direction)->toBe(PaymentDirection::In)
        ->and($payment->party_type)->toBe(PartyType::Customer)
        ->and($payment->party_id)->toBe($this->world->walkIn->id)
        ->and($payment->amount->paisa)->toBe(300_000)
        ->and(PaymentAllocation::query()->sole()->only(['allocatable_type', 'allocatable_id']))
        ->toBe(['allocatable_type' => AllocatableType::Sale, 'allocatable_id' => $sale->id]);
});

it('splits tenders and needs a reference for a wallet', function (): void {
    $sale = $this->world->sell([$this->world->line($this->shirt, 2)], [
        $this->world->tender('card', 100_000),
        $this->world->tender('bkash', 200_000, 'TRX123'),
    ]);

    expect($sale->paid->paisa)->toBe(300_000)
        ->and(Payment::query()->orderBy('id')->pluck('number')->all())->toBe(['DF1-SALE-26000001/1', 'DF1-SALE-26000001/2'])
        ->and(Payment::query()->where('reference', 'TRX123')->sole()->amount->paisa)->toBe(200_000);

    expect(fn () => $this->world->sell([$this->world->line($this->shirt)], [$this->world->tender('bkash', 150_000)]))
        ->toThrow(InvalidTender::class, 'needs a reference');
});

it('gives change only from cash', function (): void {
    expect(fn () => $this->world->sell([$this->world->line($this->shirt)], [$this->world->tender('card', 200_000)]))
        ->toThrow(InvalidTender::class, 'only be given from a cash tender');

    $sale = $this->world->sell([$this->world->line($this->shirt)], [$this->world->tender('card', 100_000), $this->world->tender('cash', 100_000)]);
    expect($sale->change_given->paisa)->toBe(50_000)
        ->and(Payment::query()->orderBy('id')->get()->map(fn ($p): int => $p->amount->paisa)->all())->toBe([100_000, 50_000]);
});

it('refuses non-positive tenders, unknown methods and exchange credit outside an exchange', function (): void {
    expect(fn () => $this->world->sell([$this->world->line($this->shirt)], [$this->world->tender('cash', 0)]))
        ->toThrow(InvalidTender::class, 'greater than zero')
        ->and(fn () => $this->world->sell([$this->world->line($this->shirt)], [$this->world->tender('exchange', 150_000)]))
        ->toThrow(InvalidTender::class, 'only be used in an exchange')
        ->and(fn () => $this->world->sell([]))->toThrow(InvalidSaleDocument::class, 'no lines')
        ->and(fn () => $this->world->sell([$this->world->line($this->shirt, '0')]))->toThrow(InvalidSaleDocument::class, 'greater than zero');
});

it('puts the due on a named customer and moves the balance and last purchase', function (): void {
    $customer = $this->world->customer();
    $sale = $this->world->sell([$this->world->line($this->shirt, 2)], [$this->world->tender('cash', 100_000)], $customer);

    $customer->refresh();
    expect($sale->due->paisa)->toBe(200_000)
        ->and($sale->payment_status)->toBe(PaymentStatus::Partial)
        ->and($customer->balance->paisa)->toBe(200_000)
        ->and($customer->last_purchase_at?->equalTo($sale->sold_at))->toBeTrue();

    $unpaid = $this->world->sell([$this->world->line($this->shirt)], [], $customer);
    expect($unpaid->payment_status)->toBe(PaymentStatus::Unpaid)
        ->and($customer->refresh()->balance->paisa)->toBe(350_000);
});

it('refuses a due for the walk-in customer and writes nothing', function (): void {
    expect(fn () => $this->world->sell([$this->world->line($this->shirt)], [$this->world->tender('cash', 100_000)]))
        ->toThrow(CreditLimitExceeded::class, 'named customer');

    expect(Sale::query()->count())->toBe(0)
        ->and(Payment::query()->count())->toBe(0)
        ->and(($this->onHand)($this->shirt))->toBe('10.000');

    // The refused sale gave its number back.
    expect($this->world->sell([$this->world->line($this->shirt)], [$this->world->tender('cash', 150_000)])->number)->toBe('DF1-SALE-26000001');
});

it('enforces the credit limit unless a manager overrides', function (): void {
    $customer = $this->world->customer(['credit_limit' => Money::ofPaisa(200_000), 'balance' => Money::ofPaisa(100_000)]);
    $line = [$this->world->line($this->shirt)];

    expect(fn () => $this->world->sell($line, [], $customer, 'Cashier'))->toThrow(CreditLimitExceeded::class, 'above the credit limit');
    expect($customer->refresh()->balance->paisa)->toBe(100_000);

    // Within the limit.
    $this->world->sell($line, [$this->world->tender('cash', 60_000)], $customer, 'Cashier');
    expect($customer->refresh()->balance->paisa)->toBe(190_000);

    // A manager approves at the counter, or sells herself.
    $this->world->sell($line, [], $customer, 'Cashier', approver: $this->world->user('Manager'));
    $this->world->sell($line, [], $customer, 'Manager');
    expect($customer->refresh()->balance->paisa)->toBe(490_000);
});

it('uses the tenant default credit limit for customers without their own', function (): void {
    $this->world->tenant->forceFill(['settings' => [...$this->world->tenant->settings, 'default_credit_limit' => 100_000]])->save();
    $customer = $this->world->customer();

    expect(fn () => $this->world->sell([$this->world->line($this->shirt)], [], $customer, 'Cashier'))->toThrow(CreditLimitExceeded::class);

    $this->world->sell([$this->world->line($this->shirt)], [$this->world->tender('cash', 50_000)], $customer, 'Cashier');
    expect($customer->refresh()->balance->paisa)->toBe(100_000);
});

it('caps manual discounts by role: cashier 0 bp, a 2000 bp role, and the override permission', function (): void {
    $senior = app(TenantContext::class)->withoutTenancy(fn () => User::factory()->create(['tenant_id' => $this->world->tenant->id]));
    $role = Role::query()->create(['name' => 'Senior cashier', 'guard_name' => 'web', 'tenant_id' => $this->world->tenant->id, 'max_discount_pct_bp' => 2000]);
    $role->givePermissionTo('pos.sell');
    $senior->assignRole($role);
    $draft = fn (int $bp, ?User $approver = null): SaleDraft => new SaleDraft(
        lines: [$this->world->line($this->shirt, 1, discount: Discount::percentBp($bp))],
        tenders: [$this->world->tender('cash', 150_000)],
        approver: $approver,
    );
    $sell = fn (User $user, SaleDraft $draft): Sale => app(CompleteSale::class)->handle($user, $draft);

    expect(fn () => $sell($this->world->user('Cashier'), $draft(100)))->toThrow(DiscountCapExceeded::class, 'Line 1: a discount of 1.00 % exceeds your limit of 0.00 %')
        ->and($sell($senior, $draft(2000))->total->paisa)->toBe(120_000)
        ->and(fn () => $sell($senior, $draft(2500)))->toThrow(DiscountCapExceeded::class, 'limit of 20.00 %')
        ->and($sell($this->world->user('Manager'), $draft(2500))->total->paisa)->toBe(112_500)
        ->and($sell($this->world->user('Cashier'), $draft(2500, $this->world->user('Manager')))->total->paisa)->toBe(112_500);

    // An order discount counts against the cap too.
    expect(fn () => app(CompleteSale::class)->handle($this->world->user('Cashier'), new SaleDraft(
        lines: [$this->world->line($this->shirt)],
        tenders: [$this->world->tender('cash', 150_000)],
        orderDiscount: Discount::amount(Money::ofPaisa(100)),
    )))->toThrow(DiscountCapExceeded::class, 'on the sale');
});

it('does not count the customer-group discount against the cap', function (): void {
    $group = CustomerGroup::factory()->create(['tenant_id' => $this->world->tenant->id, 'discount_bp' => 1000]);
    $customer = $this->world->customer(['customer_group_id' => $group->id]);

    $sale = $this->world->sell([$this->world->line($this->shirt)], [$this->world->tender('cash', 135_000)], $customer, 'Cashier');

    expect($sale->item_discount_total->paisa)->toBe(15_000)
        ->and($sale->items[0]->discount->paisa)->toBe(15_000)
        ->and($sale->total->paisa)->toBe(135_000);
});

it('refuses a sale below cost without pos.sell_below_cost', function (): void {
    $costly = $this->world->product(100_000, 95_000);
    $this->world->stock($costly, 5, 95_000);
    $lines = [$this->world->line($costly, 1, discount: Discount::amount(Money::ofPaisa(10_000)))];

    expect(fn () => $this->world->sell($lines, [$this->world->tender('cash', 90_000)], role: 'Cashier', approver: null))
        ->toThrow(DiscountCapExceeded::class); // the cashier fails on the cap first

    expect(fn () => $this->world->sell($lines, [$this->world->tender('cash', 90_000)], role: 'Cashier', approver: $this->world->user('Accountant')))
        ->toThrow(DiscountCapExceeded::class);

    $priced = [$this->world->line($costly, 1, 90_000)];
    expect(fn () => $this->world->sell($priced, [$this->world->tender('cash', 90_000)], role: 'Cashier', approver: $this->world->user('Stock Keeper')))
        ->toThrow(PriceOverrideNotAllowed::class);

    // A role allowed to change the price but not to sell below cost.
    $role = Role::query()->create(['name' => 'Pricer', 'guard_name' => 'web', 'tenant_id' => $this->world->tenant->id, 'max_discount_pct_bp' => 0]);
    $role->givePermissionTo(['pos.sell', 'pos.price_override']);
    $pricer = app(TenantContext::class)->withoutTenancy(fn () => User::factory()->create(['tenant_id' => $this->world->tenant->id]));
    $pricer->assignRole($role);
    expect(fn () => app(CompleteSale::class)->handle($pricer, new SaleDraft($priced, [$this->world->tender('cash', 90_000)])))
        ->toThrow(BelowCostSale::class, 'sells below cost');

    $sale = $this->world->sell($priced, [$this->world->tender('cash', 90_000)], role: 'Manager');
    expect($sale->items[0]->unit_price->paisa)->toBe(90_000);
});

it('needs pos.price_override for another unit price', function (): void {
    expect(fn () => $this->world->sell([$this->world->line($this->shirt, 1, 140_000)], [$this->world->tender('cash', 140_000)], role: 'Cashier'))
        ->toThrow(PriceOverrideNotAllowed::class);

    expect($this->world->sell([$this->world->line($this->shirt, 1, 160_000)], [$this->world->tender('cash', 160_000)], role: 'Manager')->total->paisa)->toBe(160_000);
});

it('back-dates for any seller within backdate_days', function (): void {
    $line = [$this->world->line($this->shirt)];
    $cash = [$this->world->tender('cash', 150_000)];

    $sale = $this->world->sell($line, $cash, soldAt: now()->subDays(3));
    expect($sale->sold_at->toDateString())->toBe(now()->subDays(3)->toDateString());

    expect(fn () => $this->world->sell($line, $cash, soldAt: now()->subDays(4)))->toThrow(BackdateNotAllowed::class, 'at most 3 days')
        ->and(fn () => $this->world->sell($line, $cash, soldAt: now()->addHour()))->toThrow(BackdateNotAllowed::class, 'future');

    // A cashier back-dates without approval.
    expect($this->world->sell($line, $cash, role: 'Cashier', soldAt: now()->subDay())->sold_at->toDateString())->toBe(now()->subDay()->toDateString());
    expect($this->world->sell($line, $cash, role: 'Cashier', soldAt: now()->subHours(2))->status)->toBe(SaleStatus::Completed);
});

it('flags a price mismatch and keeps the server total', function (): void {
    $sale = $this->world->sell([$this->world->line($this->shirt)], [$this->world->tender('cash', 150_000)], expectedTotal: Money::ofPaisa(149_000));

    expect($sale->total->paisa)->toBe(150_000)
        ->and($sale->sync_flags)->toBe(['price_mismatch'])
        ->and($sale->client_totals)->toBe(['total' => 149_000]);

    $same = $this->world->sell([$this->world->line($this->shirt)], [$this->world->tender('cash', 150_000)], expectedTotal: Money::ofPaisa(150_000));
    expect($same->sync_flags)->toBeNull();
});

it('splits a line across FIFO batches and records the COGS per batch', function (): void {
    $jeans = $this->world->product(200_000);
    $old = $this->world->stock($jeans, 2, 80_000, 120);
    $new = $this->world->stock($jeans, 5, 90_000, 30);

    $sale = $this->world->sell([$this->world->line($jeans, 3)], [$this->world->tender('cash', 600_000)]);

    $links = SaleItemBatch::query()->orderBy('id')->get();
    expect($links)->toHaveCount(2)
        ->and($links->map(fn ($l) => [$l->stock_batch_id, $l->qty->toDecimalString(), $l->unit_cost->paisa])->all())
        ->toBe([[$old->id, '2.000', 80_000], [$new->id, '1.000', 90_000]])
        ->and($sale->items[0]->cogs->paisa)->toBe(250_000)
        ->and($sale->cogs_total->paisa)->toBe(250_000)
        ->and($old->refresh()->qty_remaining->toDecimalString())->toBe('0.000')
        ->and($new->refresh()->qty_remaining->toDecimalString())->toBe('4.000')
        ->and(StockMovement::query()->where('type', StockMovementType::Sale)->where('reference_type', 'sale_items')->count())->toBe(2);
});

it('refuses an online sale beyond stock and writes nothing', function (): void {
    expect(fn () => $this->world->sell([$this->world->line($this->shirt, 11)], [$this->world->tender('cash', 1_650_000)]))
        ->toThrow(InsufficientStock::class);

    expect(Sale::query()->count())->toBe(0)->and(($this->onHand)($this->shirt))->toBe('10.000');
});

it('accepts a device sale beyond stock as a backorder flagged oversold', function (): void {
    $sale = app(CompleteSale::class)->handle($this->world->user(), new SaleDraft(
        [$this->world->line($this->shirt, 12)],
        [$this->world->tender('cash', 1_800_000)],
    ), SaleOrigin::Device);

    expect($sale->origin)->toBe(SaleOrigin::Device)
        ->and($sale->sync_flags)->toBe(['oversold'])
        ->and($sale->cogs_total->paisa)->toBe(12 * 60_000)
        ->and(($this->onHand)($this->shirt))->toBe('-2.000');
});

it('stores VAT exclusive and inclusive lines so that total = Σ line_total − order discount + rounding', function (bool $inclusive, int $price, array $expected): void {
    $this->world->tenant->forceFill(['settings' => [...$this->world->tenant->settings, 'tax_inclusive' => $inclusive]])->save();
    $this->world->branch->forceFill(['settings' => [...$this->world->branch->settings, 'cash_rounding' => 100]])->save();
    $vat = TaxRate::factory()->create(['tenant_id' => $this->world->tenant->id, 'rate_bp' => 1500]);
    $taxed = $this->world->product($price, 0, ['tax_rate_id' => $vat->id]);
    $this->world->stock($taxed, 5, 10_000);

    $sale = $this->world->sell(
        [$this->world->line($taxed, 2, discount: Discount::amount(Money::ofPaisa(1_001))), $this->world->line($this->shirt)],
        [$this->world->tender('cash', 500_000)],
        orderDiscount: Discount::amount(Money::ofPaisa(5_000)),
    );

    $lines = $sale->items->sum(fn ($item): int => $item->line_total->paisa);
    expect([$sale->subtotal->paisa, $sale->item_discount_total->paisa, $sale->order_discount->paisa, $sale->tax_total->paisa, $sale->rounding->paisa, $sale->total->paisa])
        ->toBe($expected)
        ->and($sale->total->paisa)->toBe($lines - $sale->order_discount->paisa + $sale->rounding->paisa)
        ->and($sale->items[0]->tax_rate_bp)->toBe(1500);
})->with([
    // exclusive: 2 × 1000.00 − 10.01 = 1989.99; order discount 50.00 prorated; VAT 15 % on the net
    'exclusive' => [false, 100_000, [350_000, 1_001, 5_000, 29_422, -21, 373_400]],
    // inclusive: the price contains VAT, tax_total is the part of it
    'inclusive' => [true, 115_000, [380_000, 1_001, 5_000, 29_475, 1, 374_000]],
]);

it('spends the customer advance as a customer credit tender', function (): void {
    $customer = $this->world->customer(['balance' => Money::ofPaisa(-50_000)]);
    // The advance must be backed: a payment of 50,000 with nothing allocated.
    $sale = $this->world->sell([$this->world->line($this->shirt)], [
        $this->world->tender('credit', 50_000),
        $this->world->tender('cash', 100_000),
    ], $customer);

    expect($sale->paid->paisa)->toBe(150_000)
        ->and($sale->due->paisa)->toBe(0)
        ->and($customer->refresh()->balance->paisa)->toBe(0);

    expect(fn () => $this->world->sell([$this->world->line($this->shirt)], [$this->world->tender('credit', 10_000), $this->world->tender('cash', 140_000)], $customer))
        ->toThrow(InvalidTender::class, 'only 0.00 available')
        ->and(fn () => $this->world->sell([$this->world->line($this->shirt)], [$this->world->tender('credit', 150_000)]))
        ->toThrow(InvalidTender::class, 'named customer');
});

it('returns the same sale for a repeated client uuid', function (): void {
    $uuid = '0195d5a0-0000-7000-8000-000000000001';
    $first = $this->world->sell([$this->world->line($this->shirt)], [$this->world->tender('cash', 150_000)], clientUuid: $uuid);
    $again = $this->world->sell([$this->world->line($this->shirt)], [$this->world->tender('cash', 150_000)], clientUuid: $uuid);

    expect($again->id)->toBe($first->id)->and(Sale::query()->count())->toBe(1)->and(($this->onHand)($this->shirt))->toBe('9.000');
});

it('needs pos.sell', function (): void {
    expect(fn () => $this->world->sell([$this->world->line($this->shirt)], [$this->world->tender('cash', 150_000)], role: 'Accountant'))
        ->toThrow(AuthorizationException::class);
});

it('treats another tenant\'s product, customer, method or register as not found', function (): void {
    $context = app(TenantContext::class);
    [$product, $customer, $method] = $context->withoutTenancy(function (): array {
        $other = Tenant::factory()->create();

        return [
            Product::factory()->create(['tenant_id' => $other->id]),
            Customer::factory()->create(['tenant_id' => $other->id]),
            PaymentMethod::factory()->create(['tenant_id' => $other->id]),
        ];
    });
    $register = $context->withoutTenancy(fn () => Register::factory()->create(['tenant_id' => $this->world->tenant->id]));
    $cash = [$this->world->tender('cash', 150_000)];

    expect(fn () => $this->world->sell([$this->world->line($product)], $cash))->toThrow(ModelNotFoundException::class)
        ->and(fn () => $this->world->sell([$this->world->line($this->shirt)], $cash, $customer))->toThrow(ModelNotFoundException::class)
        ->and(fn () => $this->world->sell([$this->world->line($this->shirt)], [new TenderDraft($method->id, Money::ofPaisa(150_000))]))->toThrow(ModelNotFoundException::class)
        ->and(fn () => $this->world->sell([$this->world->line($this->shirt)], $cash, registerId: $register->id))->toThrow(ModelNotFoundException::class);

    expect(Sale::query()->count())->toBe(0);
});
