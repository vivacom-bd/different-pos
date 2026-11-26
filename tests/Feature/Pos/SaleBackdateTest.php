<?php

declare(strict_types=1);

use App\Models\Product;
use App\Models\Sale;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Str;
use Tests\Fixtures\SalesWorld;

beforeEach(function (): void {
    $this->world = SalesWorld::make();
    foreach ($this->world->users as $user) {
        $user->branches()->attach($this->world->branch);
    }
    $this->shirt = $this->world->product(150_000, 60_000, ['name' => 'Cotton Shirt', 'sku' => 'SH-1']);
    $this->world->stock($this->shirt, 10, 60_000);
    $this->cashier = $this->world->user('Cashier');
    $this->owner = $this->world->user();
});

afterEach(function (): void {
    app(TenantContext::class)->forget();
});

/** @param  array<string, mixed>  $overrides */
function backdateSale(SalesWorld $world, Product $product, array $overrides = []): array
{
    return [
        'client_uuid' => (string) Str::uuid(),
        'customer_id' => $world->customer()->id,
        'lines' => [['product_id' => $product->id, 'qty' => 1000, 'discount' => ['type' => 'none', 'value' => 0]]],
        'tenders' => [['payment_method_id' => $world->methods['cash']->id, 'amount' => 150_000]],
        'expected_total' => 150_000,
        ...$overrides,
    ];
}

it('sells today when no sold_on is sent or it is today', function (?string $value): void {
    $payload = backdateSale($this->world, $this->shirt, ['sold_on' => $value ?? now()->toDateString()]);
    if ($value === 'none') {
        unset($payload['sold_on']);
    }
    $res = $this->actingAs($this->cashier)->postJson('/app/pos/sales', $payload)->assertCreated();

    expect(Sale::query()->find($res->json('sale.id'))->sold_at->toDateString())->toBe(now()->toDateString());
})->with(['none', null]);

it('back-dates a sale to the chosen day', function (): void {
    $day = now()->subDay()->toDateString();
    $res = $this->actingAs($this->owner)->postJson('/app/pos/sales', backdateSale($this->world, $this->shirt, ['sold_on' => $day]))->assertCreated();

    expect(Sale::query()->find($res->json('sale.id'))->sold_at->toDateString())->toBe($day);
});

it('lets a cashier back-date without manager approval', function (): void {
    $day = now()->subDay()->toDateString();
    $res = $this->actingAs($this->cashier)->postJson('/app/pos/sales', backdateSale($this->world, $this->shirt, ['sold_on' => $day]))->assertCreated();

    expect(Sale::query()->find($res->json('sale.id'))->sold_at->toDateString())->toBe($day);
});

it('refuses a sale older than backdate_days and a future date', function (): void {
    $this->actingAs($this->owner)->postJson('/app/pos/sales', backdateSale($this->world, $this->shirt, ['sold_on' => now()->subDays(4)->toDateString()]))
        ->assertStatus(422)->assertJsonPath('code', 'backdate_not_allowed');
    $this->actingAs($this->owner)->postJson('/app/pos/sales', backdateSale($this->world, $this->shirt, ['sold_on' => now()->addDays(2)->toDateString()]))
        ->assertStatus(422)->assertJsonPath('code', 'backdate_not_allowed');
});

it('rejects a malformed sold_on with a validation error', function (): void {
    $this->actingAs($this->owner)->postJson('/app/pos/sales', backdateSale($this->world, $this->shirt, ['sold_on' => '26/11/2026']))
        ->assertUnprocessable()->assertJsonValidationErrors('sold_on');
});
