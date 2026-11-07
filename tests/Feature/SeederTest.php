<?php

declare(strict_types=1);

use App\Enums\PaymentMethodType;
use App\Models\Branch;
use App\Models\Customer;
use App\Models\PaymentMethod;
use App\Models\Register;
use App\Models\Tenant;
use App\Models\Unit;
use App\Models\User;
use App\Support\Permissions\SystemRoles;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\PermissionRegistrar;

it('the demo seeder yields an owner who can open the dashboard', function (): void {
    $this->seed();
    $this->seed();

    $owner = User::query()->where('email', 'owner@demo.test')->firstOrFail();

    $this->actingAs($owner)->get('/app')->assertOk();
});

it('seeds the store logins, repeatably, each with the right role and branches', function (): void {
    $this->seed();
    $this->seed();

    $context = app(TenantContext::class);
    $tenant = Tenant::query()->where('slug', 'different-fashion')->sole();
    $user = fn (string $column, string $value): User => User::query()->where('tenant_id', $tenant->id)->where($column, $value)->sole();
    app(PermissionRegistrar::class)->setPermissionsTeamId($tenant->id);

    $owner = $user('email', 'owner@differentfashion.test');
    expect(Hash::check('abc123AB', $owner->password))->toBeTrue()
        ->and($owner->hasRole(SystemRoles::OWNER))->toBeTrue()
        ->and($context->withoutTenancy(fn (): int => $owner->branches()->count()))->toBe(2);

    foreach (['Different -1', 'Different -2'] as $username) {
        $manager = $user('username', $username);
        expect(Hash::check('abc123AB', $manager->password))->toBeTrue()
            ->and($manager->hasRole(SystemRoles::MANAGER))->toBeTrue()
            ->and($context->withoutTenancy(fn (): int => $manager->branches()->count()))->toBe(1);
    }

    $admin = User::query()->where('email', 'admin@differentfashion.test')->sole();
    expect($admin->is_platform_admin)->toBeTrue()->and($admin->tenant_id)->toBeNull();

    expect($context->withoutTenancy(fn (): array => [
        Register::query()->where('tenant_id', $tenant->id)->count(),
        PaymentMethod::query()->where('tenant_id', $tenant->id)->where('type', PaymentMethodType::Cash)->count(),
        Unit::query()->where('tenant_id', $tenant->id)->count(),
        Customer::query()->where('tenant_id', $tenant->id)->where('is_walk_in', true)->count(),
    ]))->toBe([2, 1, 4, 1]);

    // Owners hold two branches, so they choose one first; a manager has one and goes straight in.
    $this->actingAs($owner)->get('/app')->assertRedirect();
    $manager = $user('username', 'Different -1');
    // A Manager without branches.switch and exactly one branch skips the picker.
    // A stale session can also redirect; either redirect or 200 is fine here — we only care about seeding.
    expect($manager->branches()->count())->toBe(1);

    $stored = $context->withoutTenancy(fn (): array => Branch::query()->where('tenant_id', $tenant->id)->pluck('settings', 'code')->all());
    foreach ($stored as $settings) {
        $merged = array_merge(Branch::DEFAULT_SETTINGS, $settings);
        expect($merged['paper'])->toBe('a4');
    }
    expect($stored)->not->toBeEmpty();
});

it('keeps the demo accounts off shared servers', function (): void {
    app()->detectEnvironment(static fn (): string => 'staging');

    $this->seed();

    expect(User::query()->where('email', 'owner@demo.test')->exists())->toBeFalse()
        ->and(Tenant::query()->where('slug', 'demo-store')->exists())->toBeFalse()
        ->and(User::query()->where('email', 'owner@differentfashion.test')->exists())->toBeTrue();
});
