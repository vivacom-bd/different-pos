<?php

declare(strict_types=1);

require_once __DIR__.'/AdminHelpers.php';

use App\Actions\Users\ResetStaffPin;
use App\Exceptions\Domain\InvalidAdminData;
use App\Models\Activity;
use App\Models\User;
use App\Support\Permissions\SystemRoles;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Hash;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\Models\Role;

it('resets a staff PIN, clears the lockout and audits without the PIN', function (): void {
    [$owner, $tenant, $branch] = adminActor();
    $cashier = User::factory()->for($tenant)->create(['pin_failed_attempts' => 5, 'pin_locked_until' => now()->addHour()]);
    $cashier->branches()->attach($branch);

    $this->actingAs($owner)->post("/app/settings/users/{$cashier->id}/pin", ['pin' => '4821'])->assertRedirect()->assertSessionHasNoErrors();

    $cashier->refresh();
    expect(Hash::check('4821', (string) $cashier->pin_hash))->toBeTrue()
        ->and($cashier->pin_failed_attempts)->toBe(0)
        ->and($cashier->pin_locked_until)->toBeNull();
    $audit = Activity::query()->where('event', 'pin.reset')->where('subject_id', $cashier->id)->sole();
    expect((string) json_encode($audit->properties))->not->toContain('4821');
});

it('accepts 4 to 6 digits only', function (string $pin): void {
    [$owner, $tenant] = adminActor();
    $user = User::factory()->for($tenant)->create();

    $this->actingAs($owner)->post("/app/settings/users/{$user->id}/pin", ['pin' => $pin])->assertSessionHasErrors('pin');
    expect($user->refresh()->pin_hash)->toBeNull();
    expect(fn () => app(ResetStaffPin::class)->handle($owner, $user, $pin))->toThrow(InvalidAdminData::class);
})->with(['123', '1234567', '12a4', '']);

it('needs users.manage and 404s a user of another tenant', function (): void {
    [$owner] = adminActor();
    [$cashier, $tenant] = adminActor(SystemRoles::CASHIER);
    $colleague = User::factory()->for($tenant)->create();

    $this->actingAs($cashier)->post("/app/settings/users/{$colleague->id}/pin", ['pin' => '1234'])->assertForbidden();
    expect(fn () => app(ResetStaffPin::class)->handle($cashier, $colleague, '1234'))->toThrow(AuthorizationException::class);

    $this->actingAs($owner)->post("/app/settings/users/{$colleague->id}/pin", ['pin' => '1234'])->assertNotFound();
    expect($colleague->refresh()->pin_hash)->toBeNull();
});

it('sets an initial PIN when adding staff from the dialog', function (): void {
    [$owner, $tenant, $branch] = adminActor();

    $this->actingAs($owner)->post('/app/settings/users', [
        'name' => 'Sumi', 'username' => 'sumi', 'password' => 'a-strong-pass-1', 'pin' => '2468',
        'role_ids' => [roleIdOf($tenant, SystemRoles::CASHIER)], 'branch_ids' => [$branch->id],
    ])->assertRedirect('/app/settings/users');

    expect(Hash::check('2468', (string) User::query()->where('username', 'sumi')->value('pin_hash')))->toBeTrue();
    $this->post('/app/settings/users', ['name' => 'X', 'username' => 'x2', 'password' => 'a-strong-pass-1', 'pin' => '12',
        'role_ids' => [roleIdOf($tenant, SystemRoles::CASHIER)], 'branch_ids' => [$branch->id]])->assertSessionHasErrors('pin');
});

it('renders the add dialog and the roles deep link on the one Users & Roles page', function (): void {
    [$owner] = adminActor();

    $this->actingAs($owner)->get('/app/settings/users/create')->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('settings/users/index')->where('dialog', 'create')->has('users', 1));
    $this->get('/app/settings/roles')->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('settings/users/index')->where('focus', 'roles')->where('users.0.has_pin', false));
});

function roleIdOf($tenant, string $name): int
{
    return (int) Role::query()->where('tenant_id', $tenant->id)->where('name', $name)->value('id');
}

it('lets a non-Owner users.manage holder reset only their own PIN, not another staff member', function (): void {
    [$owner, $tenant, $branch] = adminActor();
    $role = Role::query()->create(['name' => 'Admin', 'guard_name' => 'web', 'tenant_id' => $tenant->id, 'is_system' => false, 'max_discount_pct_bp' => 0]);
    $role->givePermissionTo('users.manage');
    $admin = User::factory()->for($tenant)->create();
    $admin->branches()->attach($branch);
    $admin->assignRole($role);

    $this->actingAs($admin)
        ->post("/app/settings/users/{$owner->id}/pin", ['pin' => '1234'])
        ->assertForbidden();
    expect(fn () => app(ResetStaffPin::class)->handle($admin, $owner, '1234'))->toThrow(AuthorizationException::class);
});
