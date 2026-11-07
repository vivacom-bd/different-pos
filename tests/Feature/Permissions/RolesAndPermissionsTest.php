<?php

declare(strict_types=1);

use App\Actions\Tenancy\SeedTenantRoles;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Permissions\Permission;
use App\Support\Permissions\SystemRoles;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission as PermissionModel;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/** The expected permission list, typed out independently of the code under test. */
const EXPECTED_PERMISSIONS = [
    'dashboard.view',
    'pos.sell', 'pos.discount_override', 'pos.price_override', 'pos.backdate', 'pos.sell_below_cost', 'pos.customer_pin_override',
    'devices.manage',
    'sales.view', 'sales.void', 'returns.view', 'returns.create',
    'payments.collect', 'payments.pay',
    'customers.view', 'customers.create', 'customers.update', 'customers.delete', 'customers.reset_pin', 'customers.export',
    'sms.view', 'sms.send',
    'cards.view', 'cards.issue',
    'products.view', 'products.create', 'products.update', 'products.delete', 'products.view_cost',
    'stock.view', 'stock.adjust', 'stock.count', 'stock.transfer',
    'purchases.view', 'purchases.create', 'purchases.receive', 'purchases.cancel',
    'vendors.view', 'vendors.create', 'vendors.update', 'vendors.delete',
    'money.view', 'money.create', 'money.manage',
    'reports.view', 'reports.export', 'reports.profit', 'reports.all_branches',
    'branches.switch',
    'users.manage', 'settings.manage', 'audit.view',
    'backup.view', 'backup.run',
];

function rolePermissions(Tenant $tenant, string $role): array
{
    $names = Role::query()->where('tenant_id', $tenant->id)->where('name', $role)->firstOrFail()
        ->permissions()->pluck('name')->all();
    sort($names);

    return $names;
}

function sorted(array $names): array
{
    sort($names);

    return $names;
}

it('seeds every permission from the single list', function (): void {
    $this->seed(RolesAndPermissionsSeeder::class);

    expect(sorted(Permission::ALL))->toBe(sorted(EXPECTED_PERMISSIONS))
        ->and(count(Permission::ALL))->toBe(count(array_unique(Permission::ALL)))
        ->and(sorted(PermissionModel::query()->pluck('name')->all()))->toBe(sorted(EXPECTED_PERMISSIONS))
        ->and(PermissionModel::query()->where('guard_name', '!=', 'web')->count())->toBe(0);
});

it('creates the five system roles per tenant', function (): void {
    $tenant = Tenant::factory()->create();
    app(SeedTenantRoles::class)->handle($tenant);

    $roles = Role::query()->where('tenant_id', $tenant->id)->get();
    expect($roles->pluck('name')->sort()->values()->all())->toBe(['Accountant', 'Cashier', 'Manager', 'Owner', 'Stock Keeper'])
        ->and($roles->every(fn (Role $r): bool => (bool) $r->is_system))->toBeTrue()
        ->and($roles->pluck('max_discount_pct_bp', 'name')->all())->toEqual([
            'Owner' => 10000, 'Manager' => 2000, 'Cashier' => 0, 'Accountant' => 0, 'Stock Keeper' => 0,
        ]);

    expect(rolePermissions($tenant, 'Owner'))->toBe(sorted(EXPECTED_PERMISSIONS))
        ->and(rolePermissions($tenant, 'Manager'))->toBe(sorted(array_diff(EXPECTED_PERMISSIONS, ['users.manage', 'settings.manage', 'audit.view', 'branches.switch', 'reports.all_branches', 'backup.view', 'backup.run'])))
        ->and(rolePermissions($tenant, 'Cashier'))->toBe(sorted([
            'dashboard.view', 'pos.sell', 'sales.view', 'returns.view', 'customers.view', 'customers.create',
            'cards.view', 'products.view', 'stock.view', 'payments.collect',
        ]))
        ->and(rolePermissions($tenant, 'Accountant'))->toBe(sorted([
            'dashboard.view', 'sales.view', 'returns.view', 'customers.view', 'customers.export', 'vendors.view',
            'sms.view', 'money.view', 'money.create', 'money.manage',
            'reports.view', 'reports.export', 'reports.profit',
            'payments.collect', 'payments.pay',
        ]))
        ->and(rolePermissions($tenant, 'Stock Keeper'))->toBe(sorted([
            'products.view', 'products.create', 'products.update', 'products.delete', 'products.view_cost',
            'stock.view', 'stock.adjust', 'stock.count', 'stock.transfer',
            'purchases.view', 'purchases.create', 'purchases.receive', 'purchases.cancel',
            'vendors.view', 'vendors.create', 'vendors.update', 'vendors.delete',
            'reports.view',
        ]));
});

it('is idempotent and keeps customised discount caps', function (): void {
    $tenant = Tenant::factory()->create();
    $this->seed(RolesAndPermissionsSeeder::class);
    $snapshot = fn (): array => [
        PermissionModel::query()->count(),
        Role::query()->count(),
        DB::table('role_has_permissions')->count(),
    ];
    $before = $snapshot();

    Role::query()->where('tenant_id', $tenant->id)->where('name', 'Manager')->update(['max_discount_pct_bp' => 500]);
    $this->seed(RolesAndPermissionsSeeder::class);
    app(SeedTenantRoles::class)->handle($tenant);

    expect($snapshot())->toBe($before)
        ->and(Role::query()->where('tenant_id', $tenant->id)->where('name', 'Manager')->value('max_discount_pct_bp'))->toBe(500);
});

it('repairs a drifted system role on re-run', function (): void {
    $tenant = Tenant::factory()->create();
    app(SeedTenantRoles::class)->handle($tenant);
    Role::query()->where('tenant_id', $tenant->id)->where('name', 'Cashier')->firstOrFail()->givePermissionTo('users.manage');

    app(SeedTenantRoles::class)->handle($tenant);

    expect(rolePermissions($tenant, 'Cashier'))->not->toContain('users.manage');
});

it('keeps roles separate per tenant', function (): void {
    [$a, $b] = [Tenant::factory()->create(), Tenant::factory()->create()];
    app(SeedTenantRoles::class)->handle($a);
    app(SeedTenantRoles::class)->handle($b);

    expect(Role::query()->count())->toBe(10)
        ->and(Role::query()->where('tenant_id', $a->id)->count())->toBe(5);
});

it('does not leak a role across tenant teams', function (): void {
    [$a, $b] = [Tenant::factory()->create(), Tenant::factory()->create()];
    app(SeedTenantRoles::class)->handle($a);
    app(SeedTenantRoles::class)->handle($b);
    $user = User::factory()->for($a)->create();
    $registrar = app(PermissionRegistrar::class);

    $registrar->setPermissionsTeamId($a->id);
    $user->assignRole(SystemRoles::OWNER);
    $user->unsetRelation('roles')->unsetRelation('permissions');
    expect($user->hasRole('Owner'))->toBeTrue()
        ->and($user->can('settings.manage'))->toBeTrue();

    $registrar->setPermissionsTeamId($b->id);
    $user->unsetRelation('roles')->unsetRelation('permissions');
    expect($user->hasRole('Owner'))->toBeFalse()
        ->and($user->can('settings.manage'))->toBeFalse()
        ->and($user->getAllPermissions())->toBeEmpty();
});

it('rejects unknown permission patterns', function (): void {
    expect(fn () => Permission::expand(['nope.*']))->toThrow(InvalidArgumentException::class);
});

it('expands wildcard and prefix patterns with dedupe', function (): void {
    expect(Permission::expand(['*']))->toBe(Permission::ALL);
    expect(sorted(Permission::expand(['products.*'])))->toBe(sorted(['products.view', 'products.create', 'products.update', 'products.delete', 'products.view_cost']));
    expect(Permission::expand(['sales.view', 'sales.view']))->toBe(['sales.view']);
});

it('exposes a sensitive permission set for owner-only role edits', function (): void {
    expect(Permission::SENSITIVE)->toBe(['users.manage', 'settings.manage', 'audit.view', 'backup.view', 'backup.run']);
    expect(array_diff(Permission::SENSITIVE, Permission::ALL))->toBe([]);
});
