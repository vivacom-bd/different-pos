<?php

declare(strict_types=1);

use App\Models\Customer;
use App\Models\Product;
use App\Models\Vendor;
use App\Support\Phone\BdPhone;
use App\Support\Tenancy\TenantContext;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Fixtures\SalesUi;

beforeEach(function (): void {
    $this->world = SalesUi::world();
    $this->shirt = $this->world->product(100_000, 40_000, ['name' => 'Cotton Shirt', 'name_bn' => 'শার্ট', 'sku' => 'SH-100', 'barcode' => '8901234567']);
    $this->world->stock($this->shirt, 20, 40_000);
    $this->rahim = $this->world->customer(['name' => 'Rahim Uddin', 'name_bn' => 'রহিম', 'shop_name' => 'Rahim Store', 'phone' => '+8801718424859', 'alt_phone' => '+8801911222333']);
    $this->karim = $this->world->customer(['name' => 'Karim', 'phone' => '+8801711000222']);
    SalesUi::sale($this->world, $this->shirt, Customer::query()->findOrFail($this->rahim->id), 1, 0);
    $this->names = fn (string $term): array => Customer::query()->search($term)->orderBy('name')->pluck('name')->all();
});

afterEach(function (): void {
    app(TenantContext::class)->forget();
});

it('matches phones however they are typed', function (string $input): void {
    expect(($this->names)($input))->toBe(['Rahim Uddin']);
})->with(['01718', '1718424859', '+8801718424859', '017 18-42', '8801718', '01911222333']);

it('matches names, Bangla names and shop names, every word must match', function (): void {
    expect(($this->names)('rahim'))->toBe(['Rahim Uddin'])
        ->and(($this->names)('রহিম'))->toBe(['Rahim Uddin'])
        ->and(($this->names)('uddin store'))->toBe(['Rahim Uddin'])
        ->and(($this->names)('rahim karim'))->toBe([])
        ->and(($this->names)('rahim 01718'))->toBe(['Rahim Uddin'])
        ->and(($this->names)('rahim 01711'))->toBe([]);
});

it('matches a typed phone start at any length, but not bare short digits, wildcards, or nothing', function (): void {
    expect(($this->names)('01'))->toBe(($this->names)('0'))
        ->and(($this->names)('01'))->not->toBe([])
        ->and(($this->names)('18'))->toBe([])
        ->and(($this->names)('%'))->toBe([])
        ->and(count(($this->names)('')))->toBe(Customer::query()->count());
});

it('never finds another tenant customer by phone', function (): void {
    $other = SalesUi::world();
    $other->customer(['name' => 'Foreign', 'phone' => '+8801718999888']);
    app(TenantContext::class)->set($this->world->tenant, $this->world->branch);

    expect(($this->names)('01718999'))->toBe([]);
});

it('searches vendors by name and normalised phone', function (): void {
    Vendor::factory()->create(['tenant_id' => $this->world->tenant->id, 'name' => 'Alpha Traders', 'phone' => '+8801755123456']);
    $names = fn (string $t): array => Vendor::query()->search($t)->pluck('name')->all();

    expect($names('01755'))->toBe(['Alpha Traders'])
        ->and($names('alpha trad'))->toBe(['Alpha Traders'])
        ->and($names('alpha zzz'))->toBe([]);
});

it('searches products by name, Bangla name, sku and barcode with exact code first', function (): void {
    $this->world->product(100_000, 0, ['name' => 'Shirt Box', 'sku' => 'SH-1000', 'barcode' => '111']);
    $names = fn (string $t): array => Product::query()->search($t)->orderByCodeMatch($t)->orderBy('name')->pluck('sku')->all();

    expect($names('sh-100'))->toBe(['SH-100', 'SH-1000'])
        ->and($names('শার্ট'))->toBe(['SH-100'])
        ->and($names('8901234'))->toBe(['SH-100'])
        ->and($names('cotton 100'))->toBe(['SH-100'])
        ->and($names('cotton nothing'))->toBe([]);
});


it('filters the customers, dues and products screens', function (): void {
    $this->actingAs($this->world->user('Owner'))->withSession(['branch_id' => $this->world->branch->id]);

    $this->get('/app/customers?q=%2B8801718')->assertOk()
        ->assertInertia(fn (Assert $p) => $p->has('customers', 1)->where('customers.0.name', 'Rahim Uddin'));
    $this->get('/app/dues?q=01718')->assertOk()->assertInertia(fn (Assert $p) => $p->has('rows', 1));
    $this->get('/app/dues?q=রহিম')->assertOk()->assertInertia(fn (Assert $p) => $p->has('rows', 1));
    $this->get('/app/dues?q=01711')->assertOk()->assertInertia(fn (Assert $p) => $p->has('rows', 0));
    $this->get('/app/products?q=sh-100')->assertOk()->assertInertia(fn (Assert $p) => $p->has('rows', 1));
    $this->get('/app/products?q=cotton+8901')->assertOk()->assertInertia(fn (Assert $p) => $p->has('rows', 1));
    $this->get('/app/products?q=cotton+zzz')->assertOk()->assertInertia(fn (Assert $p) => $p->has('rows', 0));
});

it('builds phone LIKE patterns: a leading 0 or +880 anchors the prefix, bare digits match anywhere', function (): void {
    expect(BdPhone::searchPattern('019'))->toBe('+88019%')
        ->and(BdPhone::searchPattern('0191'))->toBe('+880191%')
        ->and(BdPhone::searchPattern('+880 1718-42'))->toBe('+880171842%')
        ->and(BdPhone::searchPattern('8801718'))->toBe('+8801718%')
        ->and(BdPhone::searchPattern('4859'))->toBe('%4859%')
        ->and(BdPhone::searchPattern('01'))->toBe('+8801%')
        ->and(BdPhone::searchPattern('0'))->toBe('+880%')
        ->and(BdPhone::searchPattern('48'))->toBeNull()
        ->and(BdPhone::searchPattern('rahim'))->toBeNull()
        ->and(BdPhone::looksLikePhoneStart('019'))->toBeTrue()
        ->and(BdPhone::looksLikePhoneStart('1019'))->toBeFalse();
});
