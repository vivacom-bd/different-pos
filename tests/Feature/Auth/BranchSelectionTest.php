<?php

declare(strict_types=1);

require_once __DIR__.'/../Admin/AdminHelpers.php';

use App\Support\Permissions\SystemRoles;
use Illuminate\Support\Facades\Cache;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

it('lists only the active branches the user is assigned to, last used first', function (): void {
    [$user, $tenant, $first] = adminActor();
    $second = addBranch($tenant, ['name' => 'Zed branch']);
    $inactive = addBranch($tenant, ['is_active' => false]);
    addBranch($tenant); // not assigned
    $user->branches()->attach([$second->id, $inactive->id]);
    $user->forceFill(['preferences' => ['last_branch_id' => $second->id]])->save();

    $this->actingAs($user)->get('/app/branch/select')->assertOk()->assertInertia(fn (Assert $page) => $page
        ->component('branch/select')
        ->has('branches', 2)
        ->where('branches.0.id', $second->id)
        ->where('branches.1.id', $first->id)
        ->where('lastBranchId', $second->id));
});

it('switches branch, stores it in the session and remembers it', function (): void {
    [$user, $tenant, $first] = adminActor();
    $second = addBranch($tenant);
    $user->branches()->attach($second);

    $this->actingAs($user)->post("/app/branch/{$second->id}/switch")->assertRedirect('/app');

    expect(session('branch_id'))->toBe($second->id)
        ->and($user->refresh()->preferences['last_branch_id'])->toBe($second->id);
    $this->get('/app')->assertOk()->assertInertia(fn (Assert $page) => $page->where('auth.branch_id', $second->id));
    expect($first->id)->not->toBe($second->id);
});

it('keeps the other preferences when remembering the branch', function (): void {
    [$user, $tenant] = adminActor();
    $user->forceFill(['preferences' => ['scheme' => 'rose']])->save();
    $second = addBranch($tenant);
    $user->branches()->attach($second);

    $this->actingAs($user)->post("/app/branch/{$second->id}/switch");

    expect($user->refresh()->preferences)->toBe(['scheme' => 'rose', 'last_branch_id' => $second->id]);
});

it('404s a switch to a branch the user is not assigned to, an inactive one or another tenant\'s', function (): void {
    [$user, $tenant] = adminActor();
    $unassigned = addBranch($tenant);
    $inactive = addBranch($tenant, ['is_active' => false]);
    $user->branches()->attach($inactive);
    [, , $foreign] = adminActor(SystemRoles::OWNER);

    $this->actingAs($user);
    foreach ([$unassigned, $inactive, $foreign] as $branch) {
        $this->post("/app/branch/{$branch->id}/switch")->assertNotFound();
    }
    expect($user->refresh()->preferences)->toBeNull();
});

it('requires authentication to switch', function (): void {
    [, , $branch] = adminActor();

    $this->post("/app/branch/{$branch->id}/switch")->assertRedirect('/login');
});

it('restores the last used branch on a new session instead of asking again', function (): void {
    [$user, $tenant, $first] = adminActor();
    $second = addBranch($tenant);
    $user->branches()->attach($second);
    $user->forceFill(['preferences' => ['last_branch_id' => $second->id]])->save();

    $this->actingAs($user)->get('/app')->assertOk()->assertInertia(fn (Assert $page) => $page->where('auth.branch_id', $second->id));
    expect($first->id)->not->toBe($second->id);
});

it('sends a multi-branch user with no memory to the branch picker', function (): void {
    [$user, $tenant] = adminActor();
    $user->branches()->attach(addBranch($tenant));

    $this->actingAs($user)->get('/app')->assertRedirect('/app/branch/select');
});

it('ignores a remembered branch that was deactivated', function (): void {
    [$user, $tenant, $first] = adminActor();
    $second = addBranch($tenant, ['is_active' => false]);
    $user->branches()->attach($second);
    $user->forceFill(['preferences' => ['last_branch_id' => $second->id]])->save();

    $this->actingAs($user)->get('/app')->assertOk()->assertInertia(fn (Assert $page) => $page->where('auth.branch_id', $first->id));
});

it('403s a branch switch for a Manager assigned to two branches', function (): void {
    [$user, $tenant, $first] = adminActor(SystemRoles::MANAGER);
    $second = addBranch($tenant);
    $user->branches()->attach($second);

    $this->actingAs($user)->post("/app/branch/{$second->id}/switch")->assertForbidden();
    expect($first->id)->not->toBe($second->id);
});

it('lands a two-branch Manager on the default branch without the picker', function (): void {
    [$user, $tenant, $first] = adminActor(SystemRoles::MANAGER);
    $second = addBranch($tenant);
    $user->branches()->attach($second, ['is_default' => true]);

    $this->actingAs($user)->get('/app')->assertOk()->assertInertia(fn (Assert $page) => $page->where('auth.branch_id', $second->id));
    $this->get('/app/branch/select')->assertRedirect('/app');
    expect($first->id)->not->toBe($second->id);
});

it('falls back to the remembered, then the first assigned branch for a Manager', function (): void {
    [$user, $tenant, $first] = adminActor(SystemRoles::MANAGER);
    $second = addBranch($tenant);
    $user->branches()->attach($second);
    $user->forceFill(['preferences' => ['last_branch_id' => $second->id]])->save();

    $this->actingAs($user)->get('/app')->assertInertia(fn (Assert $page) => $page->where('auth.branch_id', $second->id));

    $user->forceFill(['preferences' => null])->save();
    $this->flushSession();
    $this->get('/app')->assertInertia(fn (Assert $page) => $page->where('auth.branch_id', $first->id));
});

it('keeps the Owner on the picker when several branches and no memory', function (): void {
    [$user, $tenant] = adminActor(SystemRoles::OWNER);
    $user->branches()->attach(addBranch($tenant));

    $this->actingAs($user)->get('/app')->assertRedirect('/app/branch/select');
});

it('permissions:sync grants a new permission to Owners only and is idempotent', function (): void {
    [, $tenant] = adminActor();
    $owner = Role::query()->where('tenant_id', $tenant->id)->where('name', 'Owner')->firstOrFail();
    $owner->revokePermissionTo('branches.switch');
    Permission::query()->where('name', 'branches.switch')->delete();

    $this->artisan('permissions:sync')->assertSuccessful();
    $owner->refresh();
    expect($owner->hasPermissionTo('branches.switch'))->toBeTrue();

    $this->artisan('permissions:sync')->expectsOutput('Permissions already in sync.')->assertSuccessful();
});

it('permissions:sync repairs an Owner that lacks an existing permission (a crashed earlier run)', function (): void {
    [, $tenant] = adminActor();
    $owner = Role::query()->where('tenant_id', $tenant->id)->where('name', 'Owner')->firstOrFail();
    // The permission row exists (created by a run that crashed before the grant), but the Owner never got it.
    $owner->revokePermissionTo('branches.switch');
    $cashier = Role::query()->where('tenant_id', $tenant->id)->where('name', 'Cashier')->firstOrFail();
    $cashierHad = $cashier->hasPermissionTo('branches.switch');

    $this->artisan('permissions:sync')->expectsOutput('Granted to Owner: branches.switch')->assertSuccessful();
    expect($owner->refresh()->hasPermissionTo('branches.switch'))->toBeTrue()
        ->and($cashier->refresh()->hasPermissionTo('branches.switch'))->toBe($cashierHad);

    $this->artisan('permissions:sync')->expectsOutput('Permissions already in sync.')->assertSuccessful();
});

it('switching clears the new branch ticker cache so day numbers are fresh', function (): void {
    [$user, $tenant] = adminActor();
    $second = addBranch($tenant);
    $user->branches()->attach($second);
    $today = now()->format('Y-m-d');
    Cache::put("today-ticker:{$tenant->id}:{$second->id}:{$today}", ['total_paisa' => 999_000, 'sales' => 99], 60);

    $this->actingAs($user)->post("/app/branch/{$second->id}/switch")->assertRedirect('/app');

    expect(Cache::get("today-ticker:{$tenant->id}:{$second->id}:{$today}"))->toBeNull();
});
