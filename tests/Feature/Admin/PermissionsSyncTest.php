<?php

declare(strict_types=1);

use App\Actions\Tenancy\SeedTenantRoles;
use App\Models\Tenant;
use App\Support\Permissions\SystemRoles;
use Spatie\Permission\Models\Role;

it('adds a missing permission only to Owners and stays idempotent', function (): void {
    $tenant = Tenant::factory()->create();
    app(SeedTenantRoles::class)->handle($tenant);
    $cashier = Role::query()->where('tenant_id', $tenant->id)->where('name', SystemRoles::CASHIER)->firstOrFail();
    $owner = Role::query()->where('tenant_id', $tenant->id)->where('name', SystemRoles::OWNER)->firstOrFail();

    $owner->revokePermissionTo('devices.manage');
    expect($owner->permissions->pluck('name')->map(strval(...))->all())->not->toContain('devices.manage');

    $this->artisan('permissions:sync')->assertExitCode(0);

    $owner->refresh();
    $cashier->refresh();
    expect($owner->permissions->pluck('name')->map(strval(...))->all())->toContain('devices.manage')
        ->and($cashier->permissions->pluck('name')->map(strval(...))->all())->not->toContain('devices.manage');

    $this->artisan('permissions:sync')->assertExitCode(0);
    expect($owner->permissions->where('name', 'devices.manage')->count())->toBe(1);
});

it('re-applies default system roles on --reset-system-roles', function (): void {
    $tenant = Tenant::factory()->create();
    app(SeedTenantRoles::class)->handle($tenant);
    Role::query()->where('tenant_id', $tenant->id)->where('name', SystemRoles::CASHIER)->firstOrFail()->syncPermissions(['pos.sell']);

    $this->artisan('permissions:sync', ['--reset-system-roles' => true])->assertExitCode(0);

    $cashier = Role::query()->where('tenant_id', $tenant->id)->where('name', SystemRoles::CASHIER)->firstOrFail();
    expect($cashier->permissions->pluck('name')->map(strval(...))->all())->toContain('sales.view');
});
