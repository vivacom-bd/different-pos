<?php

declare(strict_types=1);

require_once __DIR__.'/AdminHelpers.php';

use App\Models\Branch;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Permissions\SystemRoles;
use Illuminate\Support\Facades\Hash;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

function roleId(Tenant $tenant, string $name): int
{
    return (int) Role::query()->where('tenant_id', $tenant->id)->where('name', $name)->value('id');
}

function userPayload(Tenant $tenant, Branch $branch, array $overrides = []): array
{
    return array_merge([
        'name' => 'Rina Akter',
        'email' => 'rina@shop.test',
        'username' => 'rina',
        'phone' => '01712345678',
        'password' => 'a-strong-pass-1',
        'role_ids' => [roleId($tenant, SystemRoles::CASHIER)],
        'branch_ids' => [$branch->id],
    ], $overrides);
}

it('needs users.manage for every users route', function (): void {
    [$cashier, $tenant, $branch] = adminActor(SystemRoles::CASHIER);
    $this->actingAs($cashier);

    $this->get('/app/settings/users')->assertForbidden();
    $this->get('/app/settings/users/create')->assertForbidden();
    $this->post('/app/settings/users', userPayload($tenant, $branch))->assertForbidden();
    $this->post("/app/settings/users/{$cashier->id}/deactivate")->assertForbidden();
});

it('lists the staff of this tenant only, with search and filters', function (): void {
    [$owner, $tenant, $branch] = adminActor();
    $cashier = User::factory()->for($tenant)->create(['name' => 'Karim Cashier', 'is_active' => false]);
    $cashier->branches()->attach($branch);
    $cashier->assignRole(SystemRoles::CASHIER);
    adminActor(SystemRoles::OWNER); // another tenant with its own owner
    app(PermissionRegistrar::class)->setPermissionsTeamId($tenant->id);

    $this->actingAs($owner)->get('/app/settings/users')->assertOk()->assertInertia(fn (Assert $page) => $page
        ->component('settings/users/index')->has('users', 2)->where('meta.total', 2)->where('dialog', null)->has('matrix.roles', 5));
    $this->get('/app/settings/users?q=karim')->assertInertia(fn (Assert $page) => $page->has('users', 1)->where('users.0.name', 'Karim Cashier'));
    $this->get('/app/settings/users?filter[status]=inactive')->assertInertia(fn (Assert $page) => $page->has('users', 1));
    $this->get('/app/settings/users?filter[role]='.roleId($tenant, SystemRoles::OWNER))->assertInertia(fn (Assert $page) => $page->has('users', 1)->where('users.0.id', $owner->id));
});

it('creates a user with a password, roles and branches', function (): void {
    [$owner, $tenant, $branch] = adminActor();

    $this->actingAs($owner)->post('/app/settings/users', userPayload($tenant, $branch))->assertRedirect('/app/settings/users');

    $user = User::query()->where('email', 'rina@shop.test')->firstOrFail();
    expect($user->tenant_id)->toBe($tenant->id)
        ->and($user->phone)->toBe('+8801712345678')
        ->and(Hash::check('a-strong-pass-1', $user->password))->toBeTrue()
        ->and($user->hasRole(SystemRoles::CASHIER))->toBeTrue()
        ->and($user->branches()->pluck('branches.id')->all())->toBe([$branch->id]);
});

it('rejects duplicate email, username and phone and a bad phone', function (): void {
    [$owner, $tenant, $branch] = adminActor();
    $this->actingAs($owner)->post('/app/settings/users', userPayload($tenant, $branch))->assertRedirect();

    $this->post('/app/settings/users', userPayload($tenant, $branch))->assertSessionHasErrors(['email', 'username', 'phone']);
    $this->post('/app/settings/users', userPayload($tenant, $branch, ['email' => 'x@y.test', 'username' => 'x', 'phone' => '12345']))->assertSessionHasErrors('phone');
});

it('allows the same username in another tenant', function (): void {
    [$owner, $tenant, $branch] = adminActor();
    [$otherOwner, $other] = adminActor();
    User::factory()->for($other)->create(['username' => 'rina']);

    $this->actingAs($owner)->post('/app/settings/users', userPayload($tenant, $branch))->assertRedirect('/app/settings/users');
    expect($otherOwner->tenant_id)->not->toBe($owner->tenant_id);
});

it('refuses a role or branch of another tenant', function (): void {
    [$owner, $tenant, $branch] = adminActor();
    [, $other, $otherBranch] = adminActor();

    $this->actingAs($owner);
    $this->post('/app/settings/users', userPayload($tenant, $branch, ['role_ids' => [roleId($other, SystemRoles::OWNER)]]))->assertSessionHasErrors('form');
    $this->post('/app/settings/users', userPayload($tenant, $branch, ['branch_ids' => [$otherBranch->id]]))->assertSessionHasErrors('form');
    expect(User::query()->where('email', 'rina@shop.test')->exists())->toBeFalse();
});

it('requires a role, a branch and a password when creating', function (): void {
    [$owner, $tenant, $branch] = adminActor();

    $this->actingAs($owner)->post('/app/settings/users', userPayload($tenant, $branch, ['role_ids' => [], 'branch_ids' => [], 'password' => '']))
        ->assertSessionHasErrors(['role_ids', 'branch_ids', 'password']);
});

it('edits a user, keeping the password unless a new one is given', function (): void {
    [$owner, $tenant, $branch] = adminActor();
    $this->actingAs($owner)->post('/app/settings/users', userPayload($tenant, $branch));
    $user = User::query()->where('email', 'rina@shop.test')->firstOrFail();
    $hash = $user->password;

    $payload = userPayload($tenant, $branch, ['name' => 'Rina A.', 'password' => '', 'role_ids' => [roleId($tenant, SystemRoles::MANAGER)]]);
    $this->put("/app/settings/users/{$user->id}", $payload)->assertRedirect('/app/settings/users');

    $user->refresh();
    expect($user->name)->toBe('Rina A.')->and($user->password)->toBe($hash)
        ->and($user->hasRole(SystemRoles::MANAGER))->toBeTrue()->and($user->hasRole(SystemRoles::CASHIER))->toBeFalse();

    $this->put("/app/settings/users/{$user->id}", userPayload($tenant, $branch, ['password' => 'brand-new-pass-9']))->assertRedirect();
    expect(Hash::check('brand-new-pass-9', $user->refresh()->password))->toBeTrue();
});

it('shows the edit form and 404s a user of another tenant', function (): void {
    [$owner, $tenant, $branch] = adminActor();
    [$foreign] = adminActor();

    $this->actingAs($owner)->get("/app/settings/users/{$owner->id}/edit")->assertOk()->assertInertia(fn (Assert $page) => $page
        ->component('settings/users/index')->where('dialog', 'edit')->where('editing.id', $owner->id)->has('roles', 5)->has('branches', 1));
    $this->get("/app/settings/users/{$foreign->id}/edit")->assertNotFound();
    $this->put("/app/settings/users/{$foreign->id}", userPayload($tenant, $branch))->assertNotFound();
    $this->post("/app/settings/users/{$foreign->id}/deactivate")->assertNotFound();
    expect($foreign->refresh()->is_active)->toBeTrue();
});

it('deactivates a user, who is then refused at login and logged out', function (): void {
    [$owner, $tenant, $branch] = adminActor();
    $this->actingAs($owner)->post('/app/settings/users', userPayload($tenant, $branch));
    $user = User::query()->where('email', 'rina@shop.test')->firstOrFail();

    $this->post("/app/settings/users/{$user->id}/deactivate")->assertRedirect();

    expect($user->refresh()->is_active)->toBeFalse();
    $this->post('/logout');
    $this->post('/login', ['login' => 'rina@shop.test', 'password' => 'a-strong-pass-1'])->assertSessionHasErrors('login');
    $this->assertGuest();
});

it('cannot deactivate yourself or the last owner', function (): void {
    [$owner, $tenant] = adminActor();

    $this->actingAs($owner)->post("/app/settings/users/{$owner->id}/deactivate")->assertSessionHasErrors('form');
    expect($owner->refresh()->is_active)->toBeTrue();

    // a second owner may deactivate the first, but then is the last one
    $second = User::factory()->for($tenant)->create();
    $second->branches()->attach($owner->branches()->first());
    $second->assignRole(SystemRoles::OWNER);
    $this->actingAs($second)->post("/app/settings/users/{$owner->id}/deactivate")->assertRedirect();
    expect($owner->refresh()->is_active)->toBeFalse();
});

it('only an owner can hand out or remove the Owner role, and the last owner keeps it', function (): void {
    [$owner, $tenant, $branch] = adminActor();
    $role = Role::query()->where('tenant_id', $tenant->id)->where('name', 'Owner')->firstOrFail();
    $custom = Role::query()->create(['name' => 'Admin', 'guard_name' => 'web', 'tenant_id' => $tenant->id, 'is_system' => false, 'max_discount_pct_bp' => 0]);
    $custom->givePermissionTo('users.manage');
    $admin = User::factory()->for($tenant)->create();
    $admin->branches()->attach($branch);
    $admin->assignRole($custom);

    $this->actingAs($admin)->post('/app/settings/users', userPayload($tenant, $branch, ['role_ids' => [$role->id]]))->assertSessionHasErrors('form');

    $this->actingAs($owner)->put("/app/settings/users/{$owner->id}", userPayload($tenant, $branch, ['email' => $owner->email, 'username' => 'boss', 'phone' => '01799999999', 'role_ids' => [roleId($tenant, SystemRoles::CASHIER)]]))
        ->assertSessionHasErrors('form');
    expect($owner->refresh()->hasRole(SystemRoles::OWNER))->toBeTrue();
});

it('never serialises password hashes into the users page', function (): void {
    [$owner] = adminActor();

    $json = json_encode($this->actingAs($owner)->get('/app/settings/users')->original->getData());

    expect($json)->not->toContain('legacy_password_md5')->not->toContain('$2y$')->not->toContain('argon2id');
});

it('reactivates a deactivated user and restores login', function (): void {
    [$owner, $tenant, $branch] = adminActor();
    $this->actingAs($owner)->post('/app/settings/users', userPayload($tenant, $branch));
    $user = User::query()->where('email', 'rina@shop.test')->firstOrFail();

    $this->post("/app/settings/users/{$user->id}/deactivate")->assertRedirect();
    expect($user->refresh()->is_active)->toBeFalse();

    $this->post("/app/settings/users/{$user->id}/reactivate")->assertRedirect();
    expect($user->refresh()->is_active)->toBeTrue();

    $this->post('/logout');
    $this->post('/login', ['login' => 'rina@shop.test', 'password' => 'a-strong-pass-1'])->assertRedirect();
    $this->assertAuthenticated();
});

it('refuses to reactivate an active user or a foreign user', function (): void {
    [$owner, $tenant, $branch] = adminActor();
    [$foreign] = adminActor();

    $this->actingAs($owner);
    $this->post("/app/settings/users/{$owner->id}/reactivate")->assertSessionHasErrors('form');
    $this->post("/app/settings/users/{$foreign->id}/reactivate")->assertNotFound();
});
