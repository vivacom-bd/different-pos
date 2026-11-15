<?php

declare(strict_types=1);

use App\Actions\Sales\CompleteSale;
use App\Actions\Sales\Data\SaleDraft;
use App\Enums\SaleOrigin;
use App\Models\Courier;
use App\Models\Product;
use App\Models\Sale;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia;
use Tests\Fixtures\SalesWorld;

beforeEach(function (): void {
    $this->world = SalesWorld::make();
    foreach ($this->world->users as $user) {
        $user->branches()->attach($this->world->branch);
    }
    $this->shirt = $this->world->product(150_000, 60_000, ['name' => 'Cotton Shirt', 'sku' => 'SH-1']);
    $this->world->stock($this->shirt, 10, 60_000);
    $this->cashier = $this->world->user('Cashier');
});

afterEach(function (): void {
    app(TenantContext::class)->forget();
});

/** @param  array<string, mixed>  $overrides */
function courierSale(SalesWorld $world, Product $product, array $overrides = []): array
{
    return [
        'client_uuid' => (string) Str::uuid(),
        'lines' => [['product_id' => $product->id, 'qty' => 1000, 'discount' => ['type' => 'none', 'value' => 0]]],
        'tenders' => [['payment_method_id' => $world->methods['cash']->id, 'amount' => 150_000]],
        'expected_total' => 150_000,
        ...$overrides,
    ];
}

// Courier per sale

it('stores the courier chosen at the register on the sale, trimmed', function (): void {
    $customer = $this->world->customer();
    $res = $this->actingAs($this->cashier)->postJson('/app/pos/sales', courierSale($this->world, $this->shirt, ['customer_id' => $customer->id, 'courier_name' => '  Pathao ']))->assertCreated();

    expect(Sale::query()->find($res->json('sale.id'))->courier_name)->toBe('Pathao');
});

it('stores no courier when none or blank is sent', function (?string $value): void {
    $customer = $this->world->customer();
    $res = $this->actingAs($this->cashier)->postJson('/app/pos/sales', courierSale($this->world, $this->shirt, ['customer_id' => $customer->id, 'courier_name' => $value]))->assertCreated();

    expect(Sale::query()->find($res->json('sale.id'))->courier_name)->toBeNull();
})->with([null, '', '   ']);

it('refuses an over-long courier name online but cuts it for a synced sale instead of rejecting', function (): void {
    $customer = $this->world->customer();
    $this->actingAs($this->cashier)->postJson('/app/pos/sales', courierSale($this->world, $this->shirt, ['customer_id' => $customer->id, 'courier_name' => str_repeat('x', 101)]))
        ->assertUnprocessable()->assertJsonValidationErrors('courier_name');

    $sale = app(CompleteSale::class)->handle($this->world->user(), new SaleDraft(
        [$this->world->line($this->shirt, 1)],
        [$this->world->tender('cash', 150_000)],
        $customer->id,
        courierName: str_repeat('y', 150),
    ), SaleOrigin::Device);

    expect(mb_strlen((string) $sale->courier_name))->toBe(100);
});

it('prints the sale courier on A4 and thermal, and nothing when unset', function (): void {
    $customer = $this->world->customer(['name' => 'Rahim', 'shop_name' => 'Rahim Store', 'address_line' => 'Holding 7, Mirpur']);
    $withCourier = $this->world->sell([$this->world->line($this->shirt, 1)], [$this->world->tender('cash', 150_000)], $customer, courierName: 'Steadfast');
    $without = $this->world->sell([$this->world->line($this->shirt, 1)], [$this->world->tender('cash', 150_000)], $customer);

    foreach (['a4', '80'] as $paper) {
        $this->actingAs($this->cashier)->get(route('sales.receipt', [$withCourier->id, 'paper' => $paper, 'lang' => 'en']))
            ->assertOk()->assertSee('Courier')->assertSee('Steadfast')->assertSee('Holding 7, Mirpur')->assertSee('Rahim Store');
        $this->actingAs($this->cashier)->get(route('sales.receipt', [$without->id, 'paper' => $paper, 'lang' => 'en']))
            ->assertOk()->assertDontSee('Courier');
    }
});

it('hands the register this tenant\'s active couriers and the new settings', function (): void {
    Courier::factory()->create(['tenant_id' => $this->world->tenant->id, 'name' => 'RedX']);
    Courier::factory()->create(['tenant_id' => $this->world->tenant->id, 'name' => 'Old', 'is_active' => false]);
    $foreign = SalesWorld::make();
    Courier::factory()->create(['tenant_id' => $foreign->tenant->id, 'name' => 'Foreign Courier']);
    app(TenantContext::class)->forget();

    $this->actingAs($this->cashier)->get('/app/pos')->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page->component('pos/register', false)
            ->where('couriers', ['RedX'])
            ->where('settings.require_customer', false)
            ->has('settings.auto_print'));
});

// Customer required

it('refuses a walk-in sale online when a customer is required, and lets a named customer through', function (): void {
    $this->world->tenant->forceFill(['settings' => [...$this->world->tenant->settings, 'require_customer_on_sale' => true]])->save();

    $this->actingAs($this->cashier)->postJson('/app/pos/sales', courierSale($this->world, $this->shirt))
        ->assertUnprocessable()->assertJsonPath('code', 'customer_required');
    expect(Sale::query()->count())->toBe(0);

    $customer = $this->world->customer();
    $this->actingAs($this->cashier)->postJson('/app/pos/sales', courierSale($this->world, $this->shirt, ['customer_id' => $customer->id]))->assertCreated();
});

it('defaults to requiring a customer and allows walk-in sales when switched off', function (): void {
    $this->world->tenant->forceFill(['settings' => []])->save();
    expect($this->world->tenant->refresh()->setting('require_customer_on_sale'))->toBeTrue();

    $this->world->tenant->forceFill(['settings' => ['require_customer_on_sale' => false]])->save();
    $this->actingAs($this->cashier)->postJson('/app/pos/sales', courierSale($this->world, $this->shirt))->assertCreated();
});

it('flags a synced device sale without a customer instead of rejecting it', function (): void {
    $this->world->tenant->forceFill(['settings' => [...$this->world->tenant->settings, 'require_customer_on_sale' => true]])->save();

    $sale = app(CompleteSale::class)->handle($this->world->user(), new SaleDraft(
        [$this->world->line($this->shirt, 1)],
        [$this->world->tender('cash', 150_000)],
    ), SaleOrigin::Device);

    expect($sale->sync_flags)->toBe(['customer_missing']);
});

// Courier required without a default

function makeCouriers(bool $withDefault): void
{
    Courier::query()->create(['name' => 'Pathao Courier', 'is_default' => $withDefault, 'is_active' => true]);
    Courier::query()->create(['name' => 'RedX', 'is_default' => false, 'is_active' => true]);
}

it('refuses an online sale without a courier when couriers exist and none is default', function (): void {
    $customer = $this->world->customer();
    makeCouriers(false);

    $this->actingAs($this->cashier)->postJson('/app/pos/sales', courierSale($this->world, $this->shirt, ['customer_id' => $customer->id]))
        ->assertUnprocessable()->assertJsonPath('code', 'courier_required')->assertJsonPath('message', 'Choose a courier for this sale before completing it.');
    expect(Sale::query()->count())->toBe(0);

    $this->actingAs($this->cashier)->postJson('/app/pos/sales', courierSale($this->world, $this->shirt, ['customer_id' => $customer->id, 'courier_name' => 'RedX']))->assertCreated();
});

it('keeps the courier optional when a default exists or there are no couriers', function (): void {
    $customer = $this->world->customer();
    makeCouriers(true);

    $this->actingAs($this->cashier)->postJson('/app/pos/sales', courierSale($this->world, $this->shirt, ['customer_id' => $customer->id]))->assertCreated();
});

it('flags a synced device sale without a courier instead of rejecting it', function (): void {
    makeCouriers(false);

    $sale = app(CompleteSale::class)->handle($this->world->user(), new SaleDraft(
        [$this->world->line($this->shirt, 1)],
        [$this->world->tender('cash', 150_000)],
        $this->world->customer()->id,
    ), SaleOrigin::Device);

    expect($sale->sync_flags)->toBe(['courier_missing']);
});

it('tells the register whether a courier is required', function (): void {
    makeCouriers(false);
    $this->actingAs($this->cashier)->get('/app/pos')->assertInertia(fn (AssertableInertia $page) => $page->where('courier_required', true));

    Courier::query()->where('name', 'RedX')->update(['is_default' => true]);
    $this->actingAs($this->cashier)->get('/app/pos')->assertInertia(fn (AssertableInertia $page) => $page->where('courier_required', false));
});

it('shares the branch auto_print setting with every page', function (bool $on): void {
    $this->world->branch->forceFill(['settings' => [...($this->world->branch->settings ?? []), 'auto_print' => $on]])->save();

    $this->actingAs($this->cashier)->get('/app/pos')->assertInertia(fn (AssertableInertia $page) => $page->where('auth.branch_auto_print', $on));
})->with([true, false]);

it('seeds the default couriers once per tenant that has none', function (): void {
    $migration = require database_path('migrations/2026_11_15_000100_seed_default_couriers.php');
    $other = SalesWorld::make();
    app(TenantContext::class)->forget();

    DB::table('couriers')->delete();
    DB::table('couriers')->insert(['tenant_id' => $other->tenant->id, 'name' => 'Mine', 'is_default' => true, 'is_active' => true, 'created_at' => now(), 'updated_at' => now()]);

    $migration->up();
    $migration->up();

    $db = DB::table('couriers');
    expect((clone $db)->where('tenant_id', $this->world->tenant->id)->count())->toBe(count(Courier::DEFAULT_NAMES))
        ->and((clone $db)->where('tenant_id', $this->world->tenant->id)->where('is_default', true)->exists())->toBeFalse()
        ->and((clone $db)->where('tenant_id', $this->world->tenant->id)->where('is_active', false)->exists())->toBeFalse()
        ->and((clone $db)->where('tenant_id', $other->tenant->id)->pluck('name')->all())->toBe(['Mine']);
});
