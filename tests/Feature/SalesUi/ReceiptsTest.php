<?php

declare(strict_types=1);

use App\Models\Sale;
use App\Support\Tenancy\TenantContext;
use Tests\Fixtures\SalesUi;

beforeEach(function (): void {
    $this->world = SalesUi::world();
    $this->world->tenant->update(['vat_bin' => '000123456-0101', 'name' => 'Different Fashion']);
    $this->shirt = $this->world->product(123_450, 77_700, ['name' => 'Silk Panjabi', 'sku' => 'SP-01']);
    $this->world->stock($this->shirt, 10, 77_700);
    $this->customer = $this->world->customer([
        'name' => 'Rahim', 'shop_name' => 'Rahim Store', 'customer_no' => 10000016, 'phone' => '+8801711000111',
        'vat_bin' => '000999888-0202', 'address_line' => 'House 9, Road 4', 'pin_hash' => 'secret-pin-hash',
    ]);
    $this->sale = SalesUi::sale($this->world, $this->shirt, $this->customer, 2, 200_000);
});

afterEach(function (): void {
    app(TenantContext::class)->forget();
});

it('prints thermal 80 and 58 mm receipts', function (string $paper): void {
    $this->actingAs($this->world->user('Cashier'))->get(route('sales.receipt', [$this->sale->id, 'paper' => $paper, 'lang' => 'en']))
        ->assertOk()
        ->assertSee('size: '.$paper.'mm auto', false)
        ->assertSee('paper-'.$paper, false)
        ->assertSee((string) $this->sale->number)
        ->assertSee('Silk Panjabi')
        ->assertSee('৳2,469.00')
        ->assertSee('1000 0016')
        ->assertDontSee('৭৭');
})->with(['80', '58']);

it('prints an A4 tax invoice with both BINs, customer number and shop name', function (): void {
    $this->actingAs($this->world->user('Cashier'))->get(route('sales.receipt', [$this->sale->id, 'paper' => 'a4', 'lang' => 'en']))
        ->assertOk()
        ->assertSee('size: A4', false)
        ->assertSee('Tax invoice')
        ->assertSee('000123456-0101')
        ->assertSee('000999888-0202')
        ->assertSee('1000 0016')
        ->assertSee('Rahim Store')
        ->assertSee('VAT summary');
});

it('uses Bangla digits in bn (A4 tax invoice by default)', function (): void {
    $this->actingAs($this->world->user('Cashier'))->get(route('sales.receipt', [$this->sale->id, 'lang' => 'bn']))
        ->assertOk()
        ->assertSee('<html lang="bn">', false)
        ->assertSee('৳২,৪৬৯.০০')
        ->assertSee('কর চালান')
        ->assertDontSee('৳2,469.00');
});

it('defaults to an A4 tax invoice when no paper is given and falls back on invalid values', function (): void {
    $this->actingAs($this->world->user('Cashier'))->get(route('sales.receipt', [$this->sale->id]))
        ->assertOk()
        ->assertSee('size: A4', false)
        ->assertSee('paper-a4', false);

    $this->actingAs($this->world->user('Cashier'))->get(route('sales.receipt', [$this->sale->id, 'paper' => 'letter']))
        ->assertOk()
        ->assertSee('size: A4', false);
});

it('answers 501 for PDF until mPDF is installed', function (): void {
    if (class_exists(Mpdf\Mpdf::class)) {
        $this->markTestSkipped('mPDF is installed.');
    }

    $this->actingAs($this->world->user('Cashier'))->get(route('sales.receipt', [$this->sale->id, 'format' => 'pdf']))->assertStatus(501);
});

it('serves the public receipt without login and without phone, number, BIN, cost or PIN', function (): void {
    $token = (string) $this->sale->public_token;

    $html = $this->get(route('receipts.public', $token))->assertOk()->assertHeader('X-Robots-Tag', 'noindex, nofollow')->getContent();

    expect($html)->toContain((string) $this->sale->number)
        ->toContain('Silk Panjabi')
        ->toContain('Rahim')
        ->not->toContain('+8801711000111')
        ->not->toContain('1000 0016')
        ->not->toContain('000999888-0202')
        ->not->toContain('House 9')
        ->not->toContain('777.00')
        ->not->toContain('1,554.00')
        ->not->toContain('secret-pin-hash')
        ->not->toContain('pin_')
        ->not->toContain('id="print-now"');
});

it('404s an unknown, malformed or revoked token', function (): void {
    $this->get(route('receipts.public', str_repeat('a', 32)))->assertNotFound();
    $this->get('/r/short')->assertNotFound();

    Sale::query()->whereKey($this->sale->id)->update(['public_token' => null]);
    $this->get(route('receipts.public', str_repeat('b', 32)))->assertNotFound();
});

it('does not open another branch\'s receipt', function (): void {
    $branch = SalesUi::otherBranch($this->world);
    $other = SalesUi::inBranch($this->world, $branch, fn () => Sale::factory()->create(['tenant_id' => $this->world->tenant->id, 'branch_id' => $branch->id, 'customer_id' => $this->customer->id, 'number' => 'DF2-7']));

    $this->actingAs($this->world->user('Cashier'))->get(route('sales.receipt', $other->id))->assertNotFound();
});

it('prints the customer block (name, shop, phone, address, courier, card no.) on A4 and thermal', function (): void {
    $this->customer->update(['address_line' => 'Holding 7, Mirpur', 'courier_name' => 'Sundarban Courier']);

    foreach (['a4', '80'] as $paper) {
        $this->actingAs($this->world->user('Cashier'))->get(route('sales.receipt', [$this->sale->id, 'paper' => $paper, 'lang' => 'en']))
            ->assertOk()
            ->assertSee('Rahim')
            ->assertSee('Rahim Store')
            ->assertSee('+8801711000111')
            ->assertSee('Holding 7, Mirpur')
            ->assertSee('Sundarban Courier')
            ->assertSee('1000 0016');
    }
});

it('omits the courier line when empty', function (): void {
    $this->actingAs($this->world->user('Cashier'))->get(route('sales.receipt', [$this->sale->id, 'paper' => '80', 'lang' => 'en']))
        ->assertOk()
        ->assertSee('Address: House 9, Road 4')
        ->assertDontSee('Courier:');
});
