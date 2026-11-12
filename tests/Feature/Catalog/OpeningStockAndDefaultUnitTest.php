<?php

declare(strict_types=1);

use App\Actions\Catalog\SaveProduct;
use App\Actions\Settings\SaveBusinessSettings;
use App\Enums\StockMovementType;
use App\Exceptions\Domain\InvalidAdminData;
use App\Exceptions\Domain\InvalidCatalogData;
use App\Models\BranchStock;
use App\Models\Product;
use App\Models\StockBatch;
use App\Models\StockMovement;
use App\Models\Unit;
use App\Support\Money\Money;
use App\Support\Quantity\Quantity;
use App\Support\Tenancy\TenantContext;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Fixtures\CatalogWorld;
use Tests\Fixtures\SalesWorld;

afterEach(fn () => app(TenantContext::class)->forget());

function openingSave(SalesWorld $world, array $data = [], string $role = 'Owner'): Product
{
    $unit = Unit::query()->first() ?? Unit::factory()->create(['tenant_id' => $world->tenant->id]);

    return app(SaveProduct::class)->handle($world->user($role), [
        'name' => 'Tee', 'sku' => 'TEE-1', 'unit_id' => $unit->id, 'sale_price' => Money::ofPaisa(50000),
        'cost_price' => Money::ofPaisa(30000), ...$data,
    ]);
}

it('receives opening stock into the current branch at the cost price', function (): void {
    $world = SalesWorld::make();
    $product = openingSave($world, ['opening_qty' => Quantity::ofUnits(12)]);

    $batch = StockBatch::query()->where('product_id', $product->id)->sole();
    expect($batch->branch_id)->toBe($world->branch->id)
        ->and($batch->qty_remaining->milli)->toBe(12000)
        ->and($batch->unit_cost->paisa)->toBe(30000)
        ->and(StockMovement::query()->where('product_id', $product->id)->sole()->type)->toBe(StockMovementType::Opening)
        ->and(BranchStock::query()->where('product_id', $product->id)->sole()->qty_on_hand->milli)->toBe(12000);
});

it('ignores a missing or zero opening quantity', function (): void {
    $world = SalesWorld::make();
    openingSave($world, ['sku' => 'A']);
    openingSave($world, ['sku' => 'B', 'opening_qty' => Quantity::zero()]);

    expect(StockBatch::query()->count())->toBe(0);
});

it('rejects opening stock on update, for services, untracked products, negatives and users without stock.adjust', function (array $extra, string $role): void {
    $world = SalesWorld::make();

    expect(fn () => openingSave($world, ['opening_qty' => Quantity::ofUnits(2), ...$extra], $role))->toThrow(InvalidCatalogData::class)
        ->and(StockBatch::query()->count())->toBe(0)
        ->and(Product::query()->count())->toBe(0); // the whole save rolled back
})->with([
    'untracked' => [['track_stock' => false], 'Owner'],
    'service' => [['type' => 'service'], 'Owner'],
    'negative' => [['opening_qty' => Quantity::ofMilli(-1000)], 'Owner'],
    'no permission' => [[], 'Cashier'],
]);

it('rejects opening stock when editing an existing product', function (): void {
    $world = SalesWorld::make();
    $product = openingSave($world);

    expect(fn () => app(SaveProduct::class)->handle($world->user(), ['opening_qty' => Quantity::ofUnits(1)], $product))
        ->toThrow(InvalidCatalogData::class);
});

it('validates opening stock over HTTP: stock permission needed, variant grids refused', function (): void {
    $world = CatalogWorld::make();
    $unit = app(TenantContext::class)->withoutTenancy(fn () => Unit::factory()->create(['tenant_id' => $world->tenant->id]));
    $payload = ['name' => 'Tee', 'sku' => 'TEE-9', 'type' => 'standard', 'unit_id' => $unit->id, 'sale_price' => 50000, 'cost_price' => 30000, 'opening_qty' => 5000];

    $this->actingAs($world->user)->post('/app/products', $payload)->assertRedirect();
    expect(app(TenantContext::class)->withoutTenancy(fn () => BranchStock::query()->sole()->qty_on_hand->milli))->toBe(5000);

    $this->actingAs($world->user)->post('/app/products', [...$payload, 'sku' => 'TEE-10', 'variants' => ['option1_id' => 1, 'rows' => []]])
        ->assertSessionHasErrors('opening_qty');

    // products.create without stock.adjust: the value is rejected, the field is not offered.
    $clerk = CatalogWorld::make(null);
    $clerk->user->givePermissionTo(['products.create', 'products.view']);
    $unit = app(TenantContext::class)->withoutTenancy(fn () => Unit::factory()->create(['tenant_id' => $clerk->tenant->id]));
    $this->actingAs($clerk->user)->post('/app/products', [...$payload, 'unit_id' => $unit->id])->assertSessionHasErrors('opening_qty');
    $this->actingAs($clerk->user)->get('/app/products/create')->assertInertia(fn (Assert $page) => $page->where('form.canOpenStock', false));
});

it('lists only active units in the form, but keeps the edited product\'s own inactive unit', function (): void {
    $world = CatalogWorld::make();
    [$active, $off] = app(TenantContext::class)->withoutTenancy(fn () => [
        Unit::factory()->create(['tenant_id' => $world->tenant->id, 'short_name' => 'pcs', 'is_active' => true]),
        Unit::factory()->create(['tenant_id' => $world->tenant->id, 'short_name' => 'kg', 'is_active' => false]),
    ]);
    $product = $world->product(['unit_id' => $off->id]);

    $this->actingAs($world->user)->get('/app/products/create')->assertInertia(fn (Assert $page) => $page
        ->where('form.units', fn ($units) => collect($units)->pluck('id')->contains($active->id) && ! collect($units)->pluck('id')->contains($off->id))
        ->where('form.canOpenStock', true));
    $this->actingAs($world->user)->get("/app/products/{$product->uuid}/edit")->assertInertia(fn (Assert $page) => $page
        ->where('form.units', fn ($units) => collect($units)->pluck('id')->contains($off->id)));
});

it('defaults the unit to pcs, honours the setting, and ignores a deactivated or foreign default', function (): void {
    $world = SalesWorld::make();
    $pcs = Unit::query()->where('short_name', 'pcs')->first() ?? Unit::factory()->create(['tenant_id' => $world->tenant->id, 'short_name' => 'pcs']);
    $box = Unit::factory()->create(['tenant_id' => $world->tenant->id, 'short_name' => 'box']);
    expect(Unit::defaultId())->toBe($pcs->id);

    app(SaveBusinessSettings::class)->handle($world->user(), ['default_unit_id' => $box->id]);
    $world->tenant->refresh();
    expect(Unit::defaultId())->toBe($box->id);

    $box->update(['is_active' => false]);
    expect(Unit::defaultId())->toBe($pcs->id);

    app(SaveBusinessSettings::class)->handle($world->user(), ['default_unit_id' => '']);
    $world->tenant->refresh();
    expect(Unit::defaultId())->toBe($pcs->id);
});

it('refuses an inactive default unit and never reaches another tenant\'s unit', function (): void {
    $world = CatalogWorld::make();
    $other = CatalogWorld::make();
    [$off, $foreign] = app(TenantContext::class)->withoutTenancy(fn () => [
        Unit::factory()->create(['tenant_id' => $world->tenant->id, 'is_active' => false]),
        Unit::factory()->create(['tenant_id' => $other->tenant->id]),
    ]);
    $world->enter();

    expect(fn () => app(SaveBusinessSettings::class)->handle($world->user, ['default_unit_id' => $off->id]))->toThrow(InvalidAdminData::class);

    $valid = ['name' => 'X', 'currency' => 'BDT', 'timezone' => 'Asia/Dhaka', 'locale' => 'en', 'tax_inclusive' => true,
        'backdate_days' => 3, 'customer_pin_policy' => 'off', 'offline_credit_cap' => 0];
    $this->actingAs($world->user)->put('/app/settings/business', [...$valid, 'default_unit_id' => $foreign->id])->assertSessionHasErrors('default_unit_id');
    $this->actingAs($world->user)->put('/app/settings/business', [...$valid, 'default_unit_id' => $off->id])->assertSessionHasErrors();
});
