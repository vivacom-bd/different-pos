<?php

declare(strict_types=1);

use App\Actions\Sales\CollectCustomerPayment;
use App\Actions\Sales\CompleteSale;
use App\Actions\Sales\Data\CustomerPaymentDraft;
use App\Actions\Sales\Data\SaleDraft;
use App\Enums\SaleOrigin;
use App\Exceptions\Domain\CreditLimitExceeded;
use App\Models\Sale;
use App\Support\Money\Money;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia;
use Tests\Fixtures\SalesWorld;

beforeEach(function (): void {
    $this->world = SalesWorld::make();
    foreach ($this->world->users as $user) {
        $user->branches()->attach($this->world->branch);
    }
    $this->shirt = $this->world->product(150_000, 60_000, ['name' => 'Cotton Shirt', 'sku' => 'SH-1']);
    $this->world->stock($this->shirt, 20, 60_000);
    $this->cashier = $this->world->user('Cashier');
    $this->customer = $this->world->customer(['name' => 'Rahim']);
    $this->body = fn (array $o = []): array => [
        'client_uuid' => (string) Str::uuid(),
        'customer_id' => $this->customer->id,
        'lines' => [['product_id' => $this->shirt->id, 'qty' => 1000, 'discount' => ['type' => 'none', 'value' => 0]]],
        'tenders' => [],
        'expected_total' => 150_000,
        'courier_name' => 'Pathao',
        ...$o,
    ];
    $this->post = fn (array $o = []) => $this->actingAs($this->cashier)->postJson('/app/pos/sales', ($this->body)($o));
});

afterEach(function (): void {
    app(TenantContext::class)->forget();
});

it('keeps the whole due as the condition', function (): void {
    $res = ($this->post)(['cod_amount' => 150_000])->assertCreated();
    $sale = Sale::query()->find($res->json('sale.id'));

    expect($sale->cod_amount->paisa)->toBe(150_000)
        ->and($sale->due->paisa)->toBe(150_000)
        ->and($sale->openCondition()->paisa)->toBe(150_000)
        ->and($sale->dueAfterCondition()->paisa)->toBe(0);
});

it('keeps a partial condition next to a part payment', function (): void {
    $res = ($this->post)([
        'tenders' => [['payment_method_id' => $this->world->methods['cash']->id, 'amount' => 50_000]],
        'cod_amount' => 60_000,
    ])->assertCreated();
    $sale = Sale::query()->find($res->json('sale.id'));

    expect($sale->paid->paisa)->toBe(50_000)->and($sale->due->paisa)->toBe(100_000)
        ->and($sale->openCondition()->paisa)->toBe(60_000)->and($sale->dueAfterCondition()->paisa)->toBe(40_000);
});

it('refuses a condition above the due online', function (): void {
    ($this->post)([
        'tenders' => [['payment_method_id' => $this->world->methods['cash']->id, 'amount' => 100_000]],
        'cod_amount' => 50_001,
    ])->assertUnprocessable()->assertJsonPath('code', 'condition_invalid');
    expect(Sale::query()->count())->toBe(0);
});

it('refuses a condition without a courier online', function (): void {
    ($this->post)(['courier_name' => null, 'cod_amount' => 10_000])->assertUnprocessable()->assertJsonPath('code', 'condition_invalid');
});

it('rejects a negative condition at validation', function (): void {
    ($this->post)(['cod_amount' => -1])->assertUnprocessable()->assertJsonValidationErrors('cod_amount');
});

it('clamps a synced device condition to the due and flags it', function (): void {
    $draft = fn (array $o): SaleDraft => new SaleDraft(
        [$this->world->line($this->shirt, 1)],
        [$this->world->tender('cash', 100_000)],
        $this->customer->id,
        ...$o,
    );
    $over = app(CompleteSale::class)->handle($this->world->user(), $draft(['courierName' => 'Pathao', 'codAmount' => 90_000]), SaleOrigin::Device);
    $noCourier = app(CompleteSale::class)->handle($this->world->user(), $draft(['codAmount' => 10_000]), SaleOrigin::Device);
    $fine = app(CompleteSale::class)->handle($this->world->user(), $draft(['courierName' => 'Pathao', 'codAmount' => 50_000]), SaleOrigin::Device);

    expect($over->cod_amount->paisa)->toBe(50_000)->and($over->sync_flags)->toContain('condition_adjusted')
        ->and($noCourier->cod_amount->paisa)->toBe(0)->and($noCourier->sync_flags)->toContain('condition_adjusted')
        ->and($fine->cod_amount->paisa)->toBe(50_000)->and($fine->sync_flags ?? [])->not->toContain('condition_adjusted');
});

it('does not count the condition toward the credit limit', function (): void {
    $limited = $this->world->customer(['credit_limit' => Money::ofPaisa(100_000)]);

    // A 1,500 due against a 1,000 limit: refused as is, allowed when 600+ of it is the courier's condition.
    ($this->post)(['customer_id' => $limited->id])->assertUnprocessable()->assertJsonPath('code', 'credit_limit_exceeded');
    ($this->post)(['customer_id' => $limited->id, 'cod_amount' => 50_000])->assertCreated();
    ($this->post)(['customer_id' => $limited->id, 'cod_amount' => 40_000])->assertUnprocessable();

    expect(fn () => app(CompleteSale::class)->handle($this->cashier, new SaleDraft(
        [$this->world->line($this->shirt, 1)],
        [],
        $limited->id,
        courierName: 'Pathao',
        codAmount: 40_000,
    )))->toThrow(CreditLimitExceeded::class);
});

it('shows the condition on the receipt and lets a due collection reduce it', function (): void {
    $res = ($this->post)([
        'tenders' => [['payment_method_id' => $this->world->methods['cash']->id, 'amount' => 30_000]],
        'cod_amount' => 80_000,
    ])->assertCreated();
    $sale = Sale::query()->find($res->json('sale.id'));

    $this->actingAs($this->cashier)->get(route('sales.receipt', [$sale->id, 'paper' => 'a4', 'lang' => 'en']))
        ->assertOk()->assertSee('Condition (collect at courier)')->assertSee('800.00')->assertSee('400.00');
    $this->actingAs($this->cashier)->get(route('sales.receipt', [$sale->id, 'paper' => 'a4', 'lang' => 'bn']))
        ->assertOk()->assertSee('কন্ডিশন (কুরিয়ারে আদায়)');

    // Collecting 1,000 leaves a 200 due, which is below the 800 condition: the condition shrinks to 200 and nothing is left for the shop.
    app(CollectCustomerPayment::class)->handle($this->world->user('Cashier'), new CustomerPaymentDraft($this->customer->id, Money::ofPaisa(100_000), $this->world->methods['cash']->id, null, null));
    $sale->refresh();

    expect($sale->due->paisa)->toBe(20_000)->and($sale->openCondition()->paisa)->toBe(20_000)->and($sale->dueAfterCondition()->paisa)->toBe(0);
});

it('leaves a receipt without a condition unchanged', function (): void {
    $res = ($this->post)(['courier_name' => null])->assertCreated();

    $this->actingAs($this->cashier)->get(route('sales.receipt', [$res->json('sale.id'), 'paper' => 'a4', 'lang' => 'en']))
        ->assertOk()->assertDontSee('Condition (collect at courier)')->assertSee('Due');
});

it('prints the condition on the chalan, and the full due when there is none', function (): void {
    $with = Sale::query()->find(($this->post)(['cod_amount' => 70_000])->json('sale.id'));
    $without = Sale::query()->find(($this->post)()->json('sale.id'));

    $this->actingAs($this->cashier)->get(route('sales.chalan', $with->id))->assertOk()->assertSee('COD amount')->assertSee('700.00');
    $this->actingAs($this->cashier)->get(route('sales.chalan', $without->id))->assertOk()->assertSee('1,500.00');
});

it('hands the sales history the open condition', function (): void {
    $sale = Sale::query()->find(($this->post)(['cod_amount' => 70_000])->json('sale.id'));

    $this->actingAs($this->cashier)->get(route('sales.show', $sale->id))->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page->where('detail.sale.condition', 70_000)->where('rows.0.condition', 70_000)->etc());
});
