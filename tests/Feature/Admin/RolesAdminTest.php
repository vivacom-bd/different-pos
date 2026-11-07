<?php

declare(strict_types=1);

require_once __DIR__.'/AdminHelpers.php';

use App\Models\Tenant;
use App\Models\User;
use App\Support\Permissions\Permission;
use App\Support\Permissions\SystemRoles;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\Models\Role;

function tenantRole(Tenant $tenant, string $name): Role
{
    return Role::query()->where('tenant_id', $tenant->id)->where('name', $name)->firstOrFail();
}

it('needs users.manage', function (): void {
    [$cashier, $tenant] = adminActor(SystemRoles::CASHIER);
    $role = tenantRole($tenant, SystemRoles::CASHIER);

    $this->actingAs($cashier);
    $this->get('/app/settings/roles')->assertForbidden();
    $this->post('/app/settings/roles', ['name' => 'X'])->assertForbidden();
    $this->put("/app/settings/roles/{$role->id}", ['max_discount_pct_bp' => 500])->assertForbidden();
    $this->delete("/app/settings/roles/{$role->id}")->assertForbidden();
});

it('shows every permission and this tenant\'s roles only', function (): void {
    [$owner] = adminActor();
    adminActor(); // another tenant

    $this->actingAs($owner)->get('/app/settings/roles')->assertOk()->assertInertia(fn (Assert $page) => $page
        ->component('settings/users/index')
        ->where('focus', 'roles')
        ->where('matrix.permissions', Permission::ALL)
        ->has('matrix.roles', 5)
        ->where('matrix.roles.0.name', SystemRoles::OWNER)
        ->where('matrix.roles.0.is_system', true)
        ->where('matrix.roles.0.users', 1));
});

it('creates a custom role and edits its permissions and discount cap', function (): void {
    [$owner, $tenant] = adminActor();

    $this->actingAs($owner)->post('/app/settings/roles', ['name' => 'Floor Lead'])->assertRedirect();
    $role = tenantRole($tenant, 'Floor Lead');
    expect((bool) $role->is_system)->toBeFalse();

    $this->put("/app/settings/roles/{$role->id}", ['permissions' => ['sales.view', 'pos.sell'], 'max_discount_pct_bp' => 1250, 'name' => 'Floor Lead'])->assertRedirect();

    $role->refresh();
    expect($role->permissions->pluck('name')->sort()->values()->all())->toBe(['pos.sell', 'sales.view'])
        ->and($role->max_discount_pct_bp)->toBe(1250);
});

it('rejects a duplicate role name, a system name and unknown permissions', function (): void {
    [$owner, $tenant] = adminActor();
    $this->actingAs($owner)->post('/app/settings/roles', ['name' => 'Floor Lead']);

    $this->post('/app/settings/roles', ['name' => 'floor lead'])->assertSessionHasErrors('form');
    $this->post('/app/settings/roles', ['name' => 'cashier'])->assertSessionHasErrors('form');
    $this->put('/app/settings/roles/'.tenantRole($tenant, 'Floor Lead')->id, ['permissions' => ['made.up']])->assertSessionHasErrors('permissions.0');
    $this->put('/app/settings/roles/'.tenantRole($tenant, 'Floor Lead')->id, ['max_discount_pct_bp' => 10001])->assertSessionHasErrors('max_discount_pct_bp');
});

it('locks the name of a system role but lets its cap and permissions change', function (): void {
    [$owner, $tenant] = adminActor();
    $cashier = tenantRole($tenant, SystemRoles::CASHIER);

    $this->actingAs($owner)->put("/app/settings/roles/{$cashier->id}", ['name' => 'Till Person'])->assertSessionHasErrors('form');
    expect($cashier->refresh()->name)->toBe(SystemRoles::CASHIER);

    $this->put("/app/settings/roles/{$cashier->id}", ['max_discount_pct_bp' => 500, 'permissions' => ['pos.sell']])->assertRedirect();
    $cashier->refresh();
    expect($cashier->max_discount_pct_bp)->toBe(500)->and($cashier->permissions->pluck('name')->all())->toBe(['pos.sell']);
});

it('keeps every permission on the Owner role', function (): void {
    [$owner, $tenant] = adminActor();
    $role = tenantRole($tenant, SystemRoles::OWNER);

    $this->actingAs($owner)->put("/app/settings/roles/{$role->id}", ['permissions' => ['sales.view'], 'max_discount_pct_bp' => 9000])->assertRedirect();

    $role->refresh();
    expect($role->permissions)->toHaveCount(count(Permission::ALL))->and($role->max_discount_pct_bp)->toBe(9000);
});

it('applies a changed permission to the next request', function (): void {
    [$owner, $tenant, $branch] = adminActor();
    $role = Role::query()->create(['name' => 'Viewer', 'guard_name' => 'web', 'tenant_id' => $tenant->id, 'is_system' => false, 'max_discount_pct_bp' => 0]);
    $staff = User::factory()->for($tenant)->create();
    $staff->branches()->attach($branch);
    $staff->assignRole($role);
    $this->actingAs($owner)->put("/app/settings/roles/{$role->id}", ['permissions' => ['users.manage']]);

    $this->actingAs($staff)->get('/app/settings/users')->assertOk();
});

it('deletes a custom role nobody holds; refuses system and held roles', function (): void {
    [$owner, $tenant, $branch] = adminActor();
    $this->actingAs($owner)->post('/app/settings/roles', ['name' => 'Temp']);
    $this->post('/app/settings/roles', ['name' => 'Held']);
    $temp = tenantRole($tenant, 'Temp');
    $held = tenantRole($tenant, 'Held');
    $worker = User::factory()->for($tenant)->create();
    $worker->branches()->attach($branch);
    $worker->assignRole($held);

    $this->delete("/app/settings/roles/{$temp->id}")->assertRedirect();
    $this->delete("/app/settings/roles/{$held->id}")->assertSessionHasErrors('form');
    $this->delete('/app/settings/roles/'.tenantRole($tenant, SystemRoles::CASHIER)->id)->assertSessionHasErrors('form');

    expect(Role::query()->whereKey($temp->id)->exists())->toBeFalse()
        ->and(Role::query()->whereKey($held->id)->exists())->toBeTrue();
});

it('404s a role of another tenant', function (): void {
    [$owner] = adminActor();
    [, $other] = adminActor();
    $foreign = tenantRole($other, SystemRoles::CASHIER);

    $this->actingAs($owner);
    $this->put("/app/settings/roles/{$foreign->id}", ['max_discount_pct_bp' => 1])->assertNotFound();
    $this->delete("/app/settings/roles/{$foreign->id}")->assertNotFound();
    expect($foreign->refresh()->max_discount_pct_bp)->toBe(0);
});

it('blocks a non-Owner from changing sensitive permissions on a role', function (): void {
    [$owner, $tenant, $branch] = adminActor();
    $role = Role::query()->create(['name' => 'Admin', 'guard_name' => 'web', 'tenant_id' => $tenant->id, 'is_system' => false, 'max_discount_pct_bp' => 0]);
    $role->givePermissionTo('users.manage');
    $admin = User::factory()->for($tenant)->create();
    $admin->branches()->attach($branch);
    $admin->assignRole($role);

    $cashier = tenantRole($tenant, SystemRoles::CASHIER);

    $this->actingAs($admin)
        ->put("/app/settings/roles/{$cashier->id}", ['permissions' => ['dashboard.view', 'backup.run']])
        ->assertSessionHasErrors('form');
    expect($cashier->refresh()->permissions->pluck('name')->map(strval(...))->all())->not->toContain('backup.run');

    $this->actingAs($owner)
        ->put("/app/settings/roles/{$cashier->id}", ['permissions' => ['dashboard.view', 'backup.run']])
        ->assertSessionHasNoErrors();
});

it('never removes the last active users.manage holder from a role', function (): void {
    [$owner, $tenant, $branch] = adminActor();
    $role = Role::query()->create(['name' => 'Lone Admin', 'guard_name' => 'web', 'tenant_id' => $tenant->id, 'is_system' => false, 'max_discount_pct_bp' => 0]);
    $role->givePermissionTo('users.manage', 'sales.view');
    $admin = User::factory()->for($tenant)->create();
    $admin->branches()->attach($branch);
    $admin->assignRole($role);
    // Owner ($owner) holds users.manage too, so strip the Owner role to leave $role as the sole holder.
    $owner->removeRole(tenantRole($tenant, SystemRoles::OWNER));
    $owner->assignRole(tenantRole($tenant, SystemRoles::CASHIER));

    $this->actingAs($admin)
        ->put("/app/settings/roles/{$role->id}", ['permissions' => ['sales.view']])
        ->assertSessionHasErrors('form');
    expect($role->refresh()->permissions->pluck('name')->map(strval(...))->all())->toContain('users.manage');
});
