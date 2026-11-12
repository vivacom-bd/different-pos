<?php

declare(strict_types=1);

use App\Actions\Purchasing\ReceivePurchase;
use App\Enums\PaymentMethodType;
use App\Enums\PurchaseStatus;
use App\Enums\StockSourceType;
use App\Models\BranchStock;
use App\Models\PaymentMethod;
use App\Models\Purchase;
use App\Models\StockBatch;
use App\Models\Vendor;
use App\Support\Money\Money;
use App\Support\Permissions\SystemRoles;
use App\Support\Quantity\Quantity;
use App\Support\Tenancy\TenantContext;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Fixtures\CatalogWorld;

afterEach(fn () => app(TenantContext::class)->forget());

function receivedPurchase(CatalogWorld $world, ?int $cost = 20000): Purchase
{
    $world->enter();

    return app(ReceivePurchase::class)->handle($world->user, [
        'vendor_id' => $world->vendor()->id,
        'items' => [['product_id' => $world->product()->id, 'qty' => Quantity::ofUnits(4), 'unit_cost' => Money::ofPaisa($cost)]],
    ]);
}

it('requires purchases.view and purchases.create', function (): void {
    $none = CatalogWorld::make(role: null);
    $this->actingAs($none->user)->get('/app/purchases')->assertForbidden();
    $this->actingAs($none->user)->get('/app/purchases/create')->assertForbidden();

    $cashier = CatalogWorld::make(SystemRoles::CASHIER);
    $this->actingAs($cashier->user)->get('/app/purchases')->assertForbidden();
});

it('receives a purchase through the page: stock, batch, vendor balance and optional payment', function (): void {
    $world = CatalogWorld::make();
    $vendor = $world->vendor();
    $product = $world->product();
    $method = $world->paymentMethod();

    $response = $this->actingAs($world->user)->post('/app/purchases', [
        'vendor_id' => $vendor->id,
        'vendor_ref' => 'INV-77',
        'discount' => 1000,
        'shipping' => 500,
        'items' => [['product_id' => $product->id, 'qty' => 3000, 'unit_cost' => 10000, 'discount' => 0]],
        'payment' => ['amount' => 10000, 'payment_method_id' => $method->id],
    ]);

    $world->enter();
    $purchase = Purchase::query()->firstOrFail();
    $response->assertRedirect("/app/purchases/{$purchase->uuid}");
    expect($purchase->total->paisa)->toBe(29500)
        ->and($purchase->paid->paisa)->toBe(10000)
        ->and($purchase->due->paisa)->toBe(19500)
        ->and($purchase->vendor_ref)->toBe('INV-77')
        ->and(BranchStock::query()->where('product_id', $product->id)->firstOrFail()->qty_on_hand->milli)->toBe(3000)
        ->and(Vendor::query()->findOrFail($vendor->id)->balance->paisa)->toBe(19500);
});

it('validates the purchase and refuses another tenant\'s vendor or product', function (): void {
    $mine = CatalogWorld::make();
    $other = CatalogWorld::make();

    $this->actingAs($mine->user)->post('/app/purchases', ['items' => []])->assertSessionHasErrors('items');
    $this->actingAs($mine->user)->post('/app/purchases', [
        'vendor_id' => $other->vendor()->id,
        'items' => [['product_id' => $other->product()->id, 'qty' => 1000, 'unit_cost' => 100]],
    ])->assertSessionHasErrors(['vendor_id', 'items.0.product_id']);
    $this->actingAs($mine->user)->post('/app/purchases', [
        'vendor_id' => $mine->vendor()->id,
        'items' => [['product_id' => $mine->product()->id, 'qty' => 0, 'unit_cost' => 100]],
    ])->assertSessionHasErrors('items.0.qty');
});

it('forbids paying inside a purchase without payments.pay', function (): void {
    $world = CatalogWorld::make(SystemRoles::STOCK_KEEPER);
    $world->user->givePermissionTo(['purchases.receive']);

    $this->actingAs($world->user)->post('/app/purchases', [
        'vendor_id' => $world->vendor()->id,
        'items' => [['product_id' => $world->product()->id, 'qty' => 1000, 'unit_cost' => 100]],
        'payment' => ['amount' => 50, 'payment_method_id' => $world->paymentMethod()->id],
    ])->assertForbidden();
});

it('lists and shows purchases with items, batches and payments', function (): void {
    $world = CatalogWorld::make();
    $purchase = receivedPurchase($world);
    $world->user->refresh();

    $this->actingAs($world->user)->get('/app/purchases')
        ->assertInertia(fn (Assert $page) => $page->component('purchases/index')->has('rows', 1)->where('rows.0.total', 80000));
    $this->actingAs($world->user)->get('/app/purchases?filter[payment]=paid')
        ->assertInertia(fn (Assert $page) => $page->has('rows', 0));

    $this->actingAs($world->user)->post("/app/purchases/{$purchase->uuid}/payments", ['amount' => 30000, 'payment_method_id' => $world->paymentMethod()->id])->assertRedirect();
    $this->actingAs($world->user)->get("/app/purchases/{$purchase->uuid}")
        ->assertInertia(fn (Assert $page) => $page->component('purchases/show')
            ->has('items', 1)->has('batches', 1)->has('payments', 1)
            ->where('batches.0.product', $purchase->items()->with('product')->first()?->product?->name)->where('batches.0.qty_received', 4000)
            ->where('purchase.imported', false)->where('purchase.received_by', $world->user->name)
            ->where('purchase.paid', 30000)->where('purchase.payment_status', 'partial')->where('can.cancel', true));
});

it('shows the opening batches the legacy import booked on an imported bill', function (): void {
    $world = CatalogWorld::make();
    $purchase = receivedPurchase($world);
    $world->user->refresh();
    // The import keeps the remainder as an `opening` batch on the purchase line and tags the bill with its legacy id.
    StockBatch::query()->update(['source_type' => StockSourceType::Opening->value]);
    $purchase->forceFill(['legacy_id' => 42])->save();

    $this->actingAs($world->user)->get("/app/purchases/{$purchase->uuid}")
        ->assertInertia(fn (Assert $page) => $page->component('purchases/show')->has('batches', 1)->where('purchase.imported', true));
});

it('cancels an untouched purchase and refuses a paid one', function (): void {
    $world = CatalogWorld::make();
    $purchase = receivedPurchase($world);

    $this->actingAs($world->user)->post("/app/purchases/{$purchase->uuid}/cancel")->assertRedirect("/app/purchases/{$purchase->uuid}");
    expect(Purchase::query()->findOrFail($purchase->id)->status)->toBe(PurchaseStatus::Cancelled);

    $second = receivedPurchase($world);
    $this->actingAs($world->user)->post("/app/purchases/{$second->uuid}/payments", ['amount' => 100, 'payment_method_id' => $world->paymentMethod()->id])->assertRedirect();
    $this->actingAs($world->user)->post("/app/purchases/{$second->uuid}/cancel")->assertSessionHasErrors('form');
    expect(Purchase::query()->findOrFail($second->id)->status)->toBe(PurchaseStatus::Received);
});

it('404s another tenant\'s purchase', function (): void {
    $mine = CatalogWorld::make();
    $theirs = receivedPurchase(CatalogWorld::make());

    app(TenantContext::class)->forget();
    $this->actingAs($mine->user)->get("/app/purchases/{$theirs->uuid}")->assertNotFound();
    $this->actingAs($mine->user)->post("/app/purchases/{$theirs->uuid}/cancel")->assertNotFound();
    $this->actingAs($mine->user)->post("/app/purchases/{$theirs->uuid}/payments", ['amount' => 1])->assertNotFound();
});

it('forbids cancelling without purchases.cancel', function (): void {
    $world = CatalogWorld::make();
    $purchase = receivedPurchase($world);
    $world->user->syncRoles([]);
    $world->user->givePermissionTo(['purchases.view']);

    $this->actingAs($world->user)->post("/app/purchases/{$purchase->uuid}/cancel")->assertForbidden();
});

it('looks products up by barcode, SKU or name and hides cost without products.view_cost', function (): void {
    $world = CatalogWorld::make();
    $world->product(['name' => 'Denim Jacket', 'sku' => 'DJ-1', 'barcode' => '8800000000011', 'cost_price' => Money::ofPaisa(45000)]);
    $other = CatalogWorld::make();
    $other->product(['name' => 'Denim Foreign', 'sku' => 'DJ-9']);

    $byBarcode = $this->actingAs($world->user)->getJson('/app/purchases/lookup?code=8800000000011')->assertOk();
    expect($byBarcode->json('exact'))->toBeTrue()
        ->and($byBarcode->json('products.0.sku'))->toBe('DJ-1')
        ->and($byBarcode->json('products.0.cost_price'))->toBe(45000)
        ->and($byBarcode->json('products.0.on_hand'))->toBe(0)
        ->and($byBarcode->json('products.0.sale_price'))->toBeInt();

    $byName = $this->actingAs($world->user)->getJson('/app/purchases/lookup?code=denim')->assertOk();
    expect($byName->json('exact'))->toBeFalse()->and($byName->json('products'))->toHaveCount(1);

    expect($this->actingAs($world->user)->getJson('/app/purchases/lookup?code=')->json('products'))->toBe([]);

    $nocost = CatalogWorld::make();
    $nocost->user->syncRoles([]);
    $nocost->user->givePermissionTo(['purchases.create']);
    $nocost->product(['sku' => 'NC-5', 'cost_price' => Money::ofPaisa(777)]);
    expect($this->actingAs($nocost->user)->getJson('/app/purchases/lookup?code=NC-5')->json('products.0.cost_price'))->toBe(0);
});

it('keeps store credit out of the vendor payment lists and refuses it from a crafted request', function (): void {
    $world = CatalogWorld::make();
    $vendor = $world->vendor();
    $product = $world->product();
    $cash = $world->paymentMethod();
    $credit = app(TenantContext::class)->withoutTenancy(
        fn (): PaymentMethod => PaymentMethod::factory()->create(['tenant_id' => $world->tenant->id, 'type' => PaymentMethodType::CustomerCredit]),
    );
    $exchange = app(TenantContext::class)->withoutTenancy(
        fn (): PaymentMethod => PaymentMethod::factory()->create(['tenant_id' => $world->tenant->id, 'type' => PaymentMethodType::ExchangeCredit]),
    );
    $world->enter();

    $this->actingAs($world->user)->get('/app/purchases/create')
        ->assertInertia(fn (Assert $page) => $page->has('paymentMethods', 1)->where('paymentMethods.0.id', $cash->id));
    $this->actingAs($world->user)->get('/app/vendors')
        ->assertInertia(fn (Assert $page) => $page->has('paymentMethods', 1)->where('paymentMethods.0.id', $cash->id));

    foreach ([$credit, $exchange] as $method) {
        $this->actingAs($world->user)->post('/app/purchases', [
            'vendor_id' => $vendor->id,
            'items' => [['product_id' => $product->id, 'qty' => 1000, 'unit_cost' => 10000]],
            'payment' => ['amount' => 100, 'payment_method_id' => $method->id],
        ])->assertSessionHasErrors('payment.payment_method_id');
    }
    expect(Purchase::query()->count())->toBe(0);

    $purchase = receivedPurchase($world);
    foreach ([$credit, $exchange] as $method) {
        $this->actingAs($world->user)->post("/app/purchases/{$purchase->uuid}/payments", ['amount' => 100, 'payment_method_id' => $method->id])
            ->assertSessionHasErrors('payment_method_id');
    }
    expect($purchase->refresh()->paid->paisa)->toBe(0);
});

it('answers a duplicate vendor bill number with a field error on the bill number', function (): void {
    $world = CatalogWorld::make();
    $vendor = $world->vendor();
    $product = $world->product();
    $payload = [
        'vendor_id' => $vendor->id,
        'vendor_ref' => 'INV-9',
        'items' => [['product_id' => $product->id, 'qty' => 1000, 'unit_cost' => 10000]],
    ];

    $this->actingAs($world->user)->post('/app/purchases', $payload)->assertSessionHasNoErrors();
    $this->actingAs($world->user)->post('/app/purchases', [...$payload, 'vendor_ref' => ' inv-9'])
        ->assertSessionHasErrors('vendor_ref');

    $world->enter();
    expect(Purchase::query()->count())->toBe(1);
});

it('returns the last received branch cost, else the cost price, as purchase_cost; vendorless bills list and show', function (): void {
    $world = CatalogWorld::make();
    $product = $world->product(['name' => 'Cost Probe', 'sku' => 'CP-1', 'cost_price' => Money::ofPaisa(45000)]);

    $first = $this->actingAs($world->user)->getJson('/app/purchases/lookup?code=CP-1')->assertOk();
    expect($first->json('products.0.purchase_cost'))->toBe(45000);

    $this->actingAs($world->user)->post('/app/purchases', [
        'vendor_id' => null,
        'items' => [['product_id' => $product->id, 'qty' => 2000, 'unit_cost' => 51000]],
    ])->assertRedirect();

    expect($this->actingAs($world->user)->getJson('/app/purchases/lookup?code=CP-1')->json('products.0.purchase_cost'))->toBe(51000);

    $purchase = Purchase::query()->latest('id')->firstOrFail();
    expect($purchase->vendor_id)->toBeNull();
    $this->actingAs($world->user)->get('/app/purchases')->assertOk()->assertInertia(fn ($page) => $page->where('rows.0.vendor', null));
    $this->actingAs($world->user)->get('/app/purchases/'.$purchase->uuid)->assertOk();

    $other = CatalogWorld::make();
    $other->product(['name' => 'Foreign', 'sku' => 'CP-1']);
    expect($this->actingAs($other->user)->getJson('/app/purchases/lookup?code=CP-1')->json('products.0.purchase_cost'))->not->toBe(51000);
});
