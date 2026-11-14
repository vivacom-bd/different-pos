<?php

declare(strict_types=1);

require_once __DIR__.'/AdminHelpers.php';

use App\Enums\PaymentMethodType;
use App\Models\Branch;
use App\Models\PaymentMethod;
use App\Models\Register;
use App\Models\TaxRate;
use App\Models\Tenant;
use App\Models\Unit;
use App\Support\Permissions\SystemRoles;
use Inertia\Testing\AssertableInertia as Assert;

it('needs settings.manage for every settings screen and write', function (): void {
    [$manager, , $branch] = adminActor(SystemRoles::MANAGER);
    $this->actingAs($manager);

    foreach (['business', 'branches', 'registers', 'payment-methods', 'taxes', 'units'] as $page) {
        $this->get("/app/settings/{$page}")->assertForbidden();
    }
    $this->put('/app/settings/business', ['name' => 'X'])->assertForbidden();
    $this->post('/app/settings/branches', ['code' => 'X', 'name' => 'X'])->assertForbidden();
    $this->post('/app/settings/registers', ['branch_id' => $branch->id, 'name' => 'X', 'code' => 'X'])->assertForbidden();
    $this->post('/app/settings/taxes', ['name' => 'X', 'rate_bp' => 0])->assertForbidden();
    $this->post('/app/settings/units', ['name' => 'X', 'short_name' => 'x'])->assertForbidden();
    $this->post('/app/settings/payment-methods', ['name' => 'X'])->assertForbidden();
});

// Business

it('shows and updates the business profile and rules', function (): void {
    [$owner, $tenant] = adminActor();

    $this->actingAs($owner)->get('/app/settings/business')->assertOk()->assertInertia(fn (Assert $page) => $page
        ->component('settings/business')->where('business.slug', $tenant->slug)->where('business.tax_inclusive', true)->where('business.require_customer_on_sale', false));

    $this->put('/app/settings/business', [
        'name' => 'Different Fashion', 'legal_name' => 'Different Fashion Ltd', 'vat_bin' => '000123456-0101', 'phone' => '01712345678',
        'currency' => 'bdt', 'timezone' => 'Asia/Dhaka', 'locale' => 'bn', 'tax_inclusive' => false, 'backdate_days' => 7,
        'return_days' => 14, 'default_credit_limit' => 5_000_000, 'customer_pin_policy' => 'required', 'offline_credit_cap' => 200_000, 'require_customer_on_sale' => false,
    ])->assertRedirect();

    $tenant->refresh();
    expect($tenant->name)->toBe('Different Fashion')->and($tenant->currency)->toBe('BDT')->and($tenant->locale)->toBe('bn')
        ->and($tenant->setting('tax_inclusive'))->toBeFalse()->and($tenant->setting('backdate_days'))->toBe(7)
        ->and($tenant->setting('return_days'))->toBe(14)->and($tenant->setting('default_credit_limit'))->toBe(5_000_000)
        ->and($tenant->setting('customer_pin_policy'))->toBe('required')->and($tenant->setting('lapsed_days'))->toBe(90)
        ->and($tenant->setting('require_customer_on_sale'))->toBeFalse();
});

it('treats empty limits as no limit and rejects bad business input', function (): void {
    [$owner, $tenant] = adminActor();
    $valid = ['name' => 'Shop', 'currency' => 'BDT', 'timezone' => 'Asia/Dhaka', 'locale' => 'en', 'tax_inclusive' => true, 'backdate_days' => 3,
        'customer_pin_policy' => 'optional', 'offline_credit_cap' => 0];

    $this->actingAs($owner)->put('/app/settings/business', $valid + ['return_days' => null, 'default_credit_limit' => null])->assertRedirect();
    expect($tenant->refresh()->setting('return_days'))->toBeNull()->and($tenant->setting('default_credit_limit'))->toBeNull();

    $this->put('/app/settings/business', ['timezone' => 'Mars/Base'] + $valid)->assertSessionHasErrors('timezone');
    $this->put('/app/settings/business', ['locale' => 'fr'] + $valid)->assertSessionHasErrors('locale');
    $this->put('/app/settings/business', ['default_credit_limit' => -1] + $valid)->assertSessionHasErrors('default_credit_limit');
    $this->put('/app/settings/business', ['name' => ''] + $valid)->assertSessionHasErrors('name');
    $this->put('/app/settings/business', ['customer_pin_policy' => 'always'] + $valid)->assertSessionHasErrors('customer_pin_policy');
});

it('only ever changes the signed-in tenant', function (): void {
    [$owner, $tenant] = adminActor();
    $other = Tenant::factory()->create(['name' => 'Other Co']);

    $this->actingAs($owner)->put('/app/settings/business', ['name' => 'Mine', 'currency' => 'BDT', 'timezone' => 'Asia/Dhaka', 'locale' => 'en',
        'tax_inclusive' => true, 'backdate_days' => 3, 'customer_pin_policy' => 'optional', 'offline_credit_cap' => 0])->assertRedirect();

    expect($tenant->refresh()->name)->toBe('Mine')->and($other->refresh()->name)->toBe('Other Co');
});

// Branches

it('creates a branch, assigns the creator and records settings', function (): void {
    [$owner, $tenant] = adminActor();

    $this->actingAs($owner)->post('/app/settings/branches', [
        'code' => 'mir', 'name' => 'Mirpur', 'phone' => '01712345678', 'settings' => ['paper' => '58', 'cash_rounding' => 100, 'auto_print' => false],
    ])->assertRedirect();

    $branch = inTenant($tenant, fn () => Branch::query()->where('code', 'MIR')->firstOrFail());
    expect($branch->name)->toBe('Mirpur')->and($branch->setting('paper'))->toBe('58')->and($branch->setting('cash_rounding'))->toBe(100)
        ->and($branch->setting('auto_print'))->toBeFalse()->and($branch->setting('allow_negative_stock'))->toBeFalse()
        ->and($owner->branches()->whereKey($branch->id)->exists())->toBeTrue();
});

it('rejects a duplicate branch code and bad settings', function (): void {
    [$owner, , $branch] = adminActor();

    $this->actingAs($owner)->post('/app/settings/branches', ['code' => strtolower($branch->code), 'name' => 'Again'])->assertSessionHasErrors('form');
    $this->post('/app/settings/branches', ['code' => 'NEW', 'name' => 'New', 'settings' => ['paper' => 'letter']])->assertSessionHasErrors('settings.paper');
});

it('defaults a new branch to A4 paper', function (): void {
    [$owner] = adminActor();

    $this->actingAs($owner)->post('/app/settings/branches', ['code' => 'NEW4', 'name' => 'New Four'])->assertRedirect();

    $branch = Branch::query()->where('code', 'NEW4')->firstOrFail();
    expect($branch->setting('paper'))->toBe('a4');
});

it('lets another tenant reuse a branch code', function (): void {
    [$owner, , $branch] = adminActor();
    [$foreignOwner] = adminActor();

    $this->actingAs($foreignOwner)->post('/app/settings/branches', ['code' => $branch->code, 'name' => 'Same code'])->assertRedirect();
    expect($owner->tenant_id)->not->toBe($foreignOwner->tenant_id);
});

it('edits and deactivates a branch but never the last active one', function (): void {
    [$owner, $tenant, $branch] = adminActor();
    $second = addBranch($tenant, ['name' => 'Second']);

    $this->actingAs($owner)->put("/app/settings/branches/{$second->id}", ['code' => $second->code, 'name' => 'Second renamed', 'is_active' => false])->assertRedirect();
    expect($second->refresh()->name)->toBe('Second renamed')->and($second->is_active)->toBeFalse();

    $this->put("/app/settings/branches/{$branch->id}", ['code' => $branch->code, 'name' => $branch->name, 'is_active' => false])->assertSessionHasErrors('form');
    expect($branch->refresh()->is_active)->toBeTrue();
});

it('404s a branch of another tenant', function (): void {
    [$owner] = adminActor();
    [, , $foreign] = adminActor();

    $this->actingAs($owner)->put("/app/settings/branches/{$foreign->id}", ['code' => 'ZZ', 'name' => 'Hijack'])->assertNotFound();
    expect($foreign->refresh()->name)->not->toBe('Hijack');
});

it('lists this tenant\'s branches with their staff count', function (): void {
    [$owner] = adminActor();
    adminActor();

    $this->actingAs($owner)->get('/app/settings/branches')->assertInertia(fn (Assert $page) => $page
        ->component('settings/branches')->has('branches', 1)->where('branches.0.users', 1));
});

// Registers

it('creates and edits registers of any branch of the tenant', function (): void {
    [$owner, $tenant, $branch] = adminActor();
    $second = addBranch($tenant);

    $this->actingAs($owner)->post('/app/settings/registers', ['branch_id' => $second->id, 'name' => 'Counter 2', 'code' => 'c2'])->assertRedirect();

    $register = inTenant($tenant, fn () => Register::query()->withoutBranchScope()->where('code', 'C2')->firstOrFail());
    expect($register->branch_id)->toBe($second->id)->and($register->number_year)->toBe((int) now()->format('Y'))->and($register->last_number)->toBe(0);

    $this->put("/app/settings/registers/{$register->id}", ['name' => 'Counter two', 'code' => 'C2', 'is_active' => false])->assertRedirect();
    expect($register->refresh()->name)->toBe('Counter two')->and($register->is_active)->toBeFalse();

    $this->get('/app/settings/registers')->assertInertia(fn (Assert $page) => $page->has('registers', 1)->has('branches', 2));
    expect($branch->id)->not->toBe($second->id);
});

it('keeps register names and codes unique per branch', function (): void {
    [$owner, $tenant, $branch] = adminActor();
    $second = addBranch($tenant);
    $this->actingAs($owner)->post('/app/settings/registers', ['branch_id' => $branch->id, 'name' => 'Main', 'code' => 'M1'])->assertRedirect();

    $this->post('/app/settings/registers', ['branch_id' => $branch->id, 'name' => 'Main', 'code' => 'M2'])->assertSessionHasErrors('form');
    $this->post('/app/settings/registers', ['branch_id' => $branch->id, 'name' => 'Other', 'code' => 'm1'])->assertSessionHasErrors('form');
    $this->post('/app/settings/registers', ['branch_id' => $second->id, 'name' => 'Main', 'code' => 'M1'])->assertRedirect();
});

it('refuses registers on, and edits of, another tenant\'s branch', function (): void {
    [$owner] = adminActor();
    [$foreignOwner, $foreignTenant, $foreignBranch] = adminActor();
    $this->actingAs($foreignOwner)->post('/app/settings/registers', ['branch_id' => $foreignBranch->id, 'name' => 'Till', 'code' => 'T1']);
    $foreignRegister = inTenant($foreignTenant, fn () => Register::query()->withoutBranchScope()->firstOrFail());

    $this->actingAs($owner);
    $this->post('/app/settings/registers', ['branch_id' => $foreignBranch->id, 'name' => 'Spy', 'code' => 'S1'])->assertNotFound();
    $this->put("/app/settings/registers/{$foreignRegister->id}", ['name' => 'Hijack', 'code' => 'T1'])->assertNotFound();
    $this->get('/app/settings/registers')->assertInertia(fn (Assert $page) => $page->has('registers', 0));
});

// Payment methods

it('creates, edits and orders payment methods', function (): void {
    [$owner, $tenant] = adminActor();

    $this->actingAs($owner)->post('/app/settings/payment-methods', ['name' => 'bKash', 'type' => 'mobile_wallet', 'requires_reference' => true, 'sort_order' => 3])->assertRedirect();
    $method = inTenant($tenant, fn () => PaymentMethod::query()->where('name', 'bKash')->firstOrFail());
    expect($method->type)->toBe(PaymentMethodType::MobileWallet)->and($method->requires_reference)->toBeTrue();

    $this->put("/app/settings/payment-methods/{$method->id}", ['name' => 'bKash Personal', 'sort_order' => 1, 'is_active' => false])->assertRedirect();
    expect($method->refresh()->name)->toBe('bKash Personal')->and($method->is_active)->toBeFalse();

    $this->post('/app/settings/payment-methods', ['name' => 'BKASH personal', 'type' => 'mobile_wallet'])->assertSessionHasErrors('form');
});

it('protects system tenders and the last cash method', function (): void {
    [$owner, $tenant] = adminActor();
    $this->actingAs($owner);
    $cash = inTenant($tenant, fn () => PaymentMethod::factory()->create(['tenant_id' => $tenant->id, 'name' => 'Cash', 'type' => PaymentMethodType::Cash]));
    $credit = inTenant($tenant, fn () => PaymentMethod::factory()->create(['tenant_id' => $tenant->id, 'name' => 'Store credit', 'type' => PaymentMethodType::CustomerCredit]));

    $this->put("/app/settings/payment-methods/{$cash->id}", ['name' => 'Cash', 'is_active' => false])->assertSessionHasErrors('form');
    $this->put("/app/settings/payment-methods/{$credit->id}", ['name' => 'Store credit', 'is_active' => false])->assertSessionHasErrors('form');
    $this->put("/app/settings/payment-methods/{$cash->id}", ['name' => 'Cash', 'type' => 'bank'])->assertSessionHasErrors('form');
    $this->post('/app/settings/payment-methods', ['name' => 'Fake credit', 'type' => 'exchange_credit'])->assertSessionHasErrors('form');
    expect($cash->refresh()->is_active)->toBeTrue()->and($cash->type)->toBe(PaymentMethodType::Cash);
});

it('does not touch another tenant\'s payment methods', function (): void {
    [$owner] = adminActor();
    [, $other] = adminActor();
    $foreign = inTenant($other, fn () => PaymentMethod::factory()->create(['tenant_id' => $other->id, 'name' => 'Theirs']));

    $this->actingAs($owner)->put("/app/settings/payment-methods/{$foreign->id}", ['name' => 'Mine'])->assertNotFound();
    $this->get('/app/settings/payment-methods')->assertInertia(fn (Assert $page) => $page->has('methods', 0));
});

// Taxes and units

it('manages tax rates in basis points with a single default', function (): void {
    [$owner, $tenant] = adminActor();
    $this->actingAs($owner)->post('/app/settings/taxes', ['name' => 'VAT 15%', 'rate_bp' => 1500, 'is_default' => true])->assertRedirect();
    $this->post('/app/settings/taxes', ['name' => 'VAT 5%', 'rate_bp' => 500, 'is_default' => true])->assertRedirect();

    $rates = inTenant($tenant, fn () => TaxRate::query()->orderBy('rate_bp')->get());
    expect($rates->pluck('is_default')->all())->toBe([true, false]);

    $this->put("/app/settings/taxes/{$rates[1]->id}", ['name' => 'VAT 15%', 'rate_bp' => 1500])->assertRedirect();
    $this->post('/app/settings/taxes', ['name' => 'Bad', 'rate_bp' => 10001])->assertSessionHasErrors('rate_bp');
    $this->post('/app/settings/taxes', ['name' => 'VAT 5%', 'rate_bp' => 500])->assertRedirect(); // same name = update, not duplicate
});

it('manages units and 404s another tenant\'s', function (): void {
    [$owner, $tenant] = adminActor();
    [, $other] = adminActor();
    $foreign = inTenant($other, fn () => Unit::factory()->create(['tenant_id' => $other->id]));

    $this->actingAs($owner)->post('/app/settings/units', ['name' => 'Kilogram', 'short_name' => 'kg', 'allows_decimal' => true])->assertRedirect();
    $unit = inTenant($tenant, fn () => Unit::query()->where('short_name', 'kg')->firstOrFail());
    expect($unit->allows_decimal)->toBeTrue();

    $this->put("/app/settings/units/{$unit->id}", ['name' => 'Kilo', 'short_name' => 'kg', 'allows_decimal' => true, 'is_active' => false])->assertRedirect();
    expect($unit->refresh()->is_active)->toBeFalse();
    $this->put("/app/settings/units/{$foreign->id}", ['name' => 'Hijack', 'short_name' => 'hj'])->assertNotFound();
    $this->get('/app/settings/units')->assertInertia(fn (Assert $page) => $page->has('units', 1));
});
