<?php

declare(strict_types=1);

use App\Actions\Catalog\DeleteProduct;
use App\Actions\Catalog\SaveProduct;
use App\Enums\DeleteOutcome;
use App\Exceptions\Domain\InvalidCatalogData;
use App\Models\BranchStock;
use App\Models\Product;
use App\Models\Tenant;
use App\Models\Unit;
use App\Support\Catalog\Ean13;
use App\Support\Money\Money;
use App\Support\Quantity\Quantity;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Tests\Fixtures\StockWorld;

beforeEach(function (): void {
    $this->world = StockWorld::make();
    $this->user = $this->world->user;
    $this->unit = app(TenantContext::class)->withoutTenancy(fn (): Unit => Unit::factory()->create(['tenant_id' => $this->world->tenant->id]));
    $this->save = fn (array $data = [], ?Product $product = null): Product => app(SaveProduct::class)->handle($this->user, [
        'name' => 'Polo shirt', 'sku' => 'POLO-1', 'unit_id' => $this->unit->id, 'sale_price' => Money::ofPaisa(129900), ...$data,
    ], $product);
});

afterEach(function (): void {
    app(TenantContext::class)->forget();
});

it('creates a product with money value objects and an auto EAN-13 barcode', function (): void {
    $product = ($this->save)(['cost_price' => '850.50', 'reorder_level' => Quantity::ofUnits(5)]);

    expect($product->sale_price->paisa)->toBe(129900)
        ->and($product->cost_price->paisa)->toBe(85050)
        ->and($product->reorder_level->toDecimalString())->toBe('5.000')
        ->and(Ean13::isValid((string) $product->barcode))->toBeTrue()
        ->and($product->barcode)->toStartWith('20')
        ->and($product->created_by)->toBe($this->user->id);

    $next = ($this->save)(['sku' => 'POLO-2']);
    expect($next->barcode)->not->toBe($product->barcode);
});

it('keeps a given barcode and the existing barcode on update', function (): void {
    $product = ($this->save)(['barcode' => '8901234567890']);
    $updated = ($this->save)(['sale_price' => Money::ofPaisa(150000), 'barcode' => ''], $product);
    $kept = ($this->save)(['name' => 'Polo shirt v2'], $product);

    expect($product->barcode)->toBe('8901234567890')
        ->and($updated->barcode)->toBe('8901234567890')
        ->and($kept->name)->toBe('Polo shirt v2')
        ->and($updated->sale_price->paisa)->toBe(150000);
});

it('forbids the MC barcode prefix', function (string $barcode): void {
    ($this->save)(['barcode' => $barcode]);
})->with(['MC1000001701', 'mc123'])->throws(InvalidCatalogData::class, 'reserved');

it('refuses a duplicate SKU or barcode within the tenant', function (): void {
    ($this->save)(['barcode' => '111']);

    expect(fn () => ($this->save)(['barcode' => '222']))->toThrow(InvalidCatalogData::class, 'product code');
    expect(fn () => ($this->save)(['sku' => 'POLO-9', 'barcode' => '111']))->toThrow(InvalidCatalogData::class, 'barcode');
});

it('rejects negative prices, missing unit and unknown type', function (): void {
    expect(fn () => ($this->save)(['sale_price' => Money::ofPaisa(-1)]))->toThrow(InvalidCatalogData::class);
    expect(fn () => ($this->save)(['unit_id' => null]))->toThrow(InvalidCatalogData::class);
    expect(fn () => ($this->save)(['type' => 'gizmo']))->toThrow(InvalidCatalogData::class);
});

it('does not track stock for a service', function (): void {
    expect(($this->save)(['type' => 'service', 'track_stock' => true])->track_stock)->toBeFalse();
});

it('deletes an unreferenced product and only deactivates a referenced one', function (): void {
    $free = ($this->save)();
    $used = ($this->save)(['sku' => 'USED']);
    app(TenantContext::class)->withoutTenancy(fn () => BranchStock::factory()->create([
        'tenant_id' => $this->world->tenant->id, 'branch_id' => $this->world->branch->id, 'product_id' => $used->id,
    ]));

    expect(app(DeleteProduct::class)->handle($this->user, $free))->toBe(DeleteOutcome::Deleted)
        ->and(Product::query()->find($free->id))->toBeNull()
        ->and(app(DeleteProduct::class)->handle($this->user, $used))->toBe(DeleteOutcome::Deactivated)
        ->and($used->refresh()->is_active)->toBeFalse()
        ->and($used->trashed())->toBeFalse();
});

it('isolates products per tenant', function (): void {
    $foreign = app(TenantContext::class)->withoutTenancy(function (): Product {
        $tenant = Tenant::factory()->create();

        return Product::factory()->create(['tenant_id' => $tenant->id, 'sku' => 'POLO-1', 'barcode' => '8901234567890']);
    });

    // The same SKU and barcode are free in this tenant; the foreign row is a 404.
    $mine = ($this->save)(['barcode' => '8901234567890']);
    expect($mine->id)->not->toBe($foreign->id);
    expect(fn () => ($this->save)(['name' => 'Hijack'], $foreign))->toThrow(ModelNotFoundException::class);
    expect(fn () => app(DeleteProduct::class)->handle($this->user, $foreign))->toThrow(ModelNotFoundException::class);

    $otherUnit = app(TenantContext::class)->withoutTenancy(fn (): Unit => Unit::factory()->create());
    expect(fn () => ($this->save)(['sku' => 'NEW', 'unit_id' => $otherUnit->id]))->toThrow(ModelNotFoundException::class);
});

it('allocates barcodes per tenant sequence without collisions across tenants', function (): void {
    $a = ($this->save)();
    $other = StockWorld::make();
    $unit = app(TenantContext::class)->withoutTenancy(fn (): Unit => Unit::factory()->create(['tenant_id' => $other->tenant->id]));
    $b = app(SaveProduct::class)->handle($other->user, ['name' => 'X', 'sku' => 'X', 'unit_id' => $unit->id, 'sale_price' => '10']);

    expect($b->barcode)->toBe($a->barcode); // each tenant has its own sequence
});
