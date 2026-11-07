<?php

declare(strict_types=1);

use App\Actions\Tenancy\SeedTenantRoles;
use App\Http\Middleware\HandleInertiaRequests;
use App\Models\Branch;
use App\Models\Sale;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Money\Money;
use App\Support\Permissions\SystemRoles;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\PermissionRegistrar;
use Tests\Fixtures\CatalogWorld;

it('shares the user\'s permissions, own branches and preferences with every page', function (): void {
    $this->withoutVite();
    $tenant = Tenant::factory()->create();
    $branch = makeBranch($tenant);
    $otherTenantBranch = makeBranch(Tenant::factory()->create());
    $user = User::factory()->for($tenant)->create(['preferences' => ['locale' => 'bn', 'scheme' => 'rose']]);
    $user->branches()->attach($branch);
    app(SeedTenantRoles::class)->handle($tenant);
    app(PermissionRegistrar::class)->setPermissionsTeamId($tenant->id);
    $user->assignRole(SystemRoles::CASHIER);

    $this->actingAs($user)->get(route('dashboard'))->assertInertia(fn (Assert $page) => $page
        ->where('auth.permissions', fn ($permissions) => in_array('pos.sell', $permissions->all(), true)
            && ! in_array('users.manage', $permissions->all(), true))
        ->where('auth.branches', [['id' => $branch->id, 'name' => $branch->name]])
        ->where('auth.branch_id', $branch->id)
        ->where('preferences.locale', 'bn')
        ->where('preferences.scheme', 'rose')
        ->where('preferences.density', 'comfortable')
        ->missing('auth.branches.1')
    );

    expect($otherTenantBranch->tenant_id)->not->toBe($tenant->id);
});

it('renders the root view with the scheme and density attributes from the preferences', function (): void {
    $this->withoutVite();
    $tenant = Tenant::factory()->create();
    $user = User::factory()->for($tenant)->create(['preferences' => ['scheme' => 'ocean', 'density' => 'compact']]);
    $user->branches()->attach(makeBranch($tenant));
    app(SeedTenantRoles::class)->handle($tenant);
    app(PermissionRegistrar::class)->setPermissionsTeamId($tenant->id);
    $user->assignRole(SystemRoles::OWNER);

    $this->actingAs($user)->get(route('dashboard'))
        ->assertOk()
        ->assertSee('data-scheme="ocean"', false)
        ->assertSee('data-density="compact"', false);
});

it('shares the role names inside the tenant and none for a platform admin', function (): void {
    $this->withoutVite();
    $tenant = Tenant::factory()->create();
    $user = User::factory()->for($tenant)->create();
    $user->branches()->attach(makeBranch($tenant));
    app(SeedTenantRoles::class)->handle($tenant);
    app(PermissionRegistrar::class)->setPermissionsTeamId($tenant->id);
    $user->assignRole(SystemRoles::CASHIER);

    $this->actingAs($user)->get(route('dashboard'))
        ->assertInertia(fn (Assert $page) => $page->where('auth.roles', [SystemRoles::CASHIER]));

    $platform = app(TenantContext::class)->withoutTenancy(static fn (): User => User::factory()->create(['tenant_id' => null, 'is_platform_admin' => true]));
    // The platform console has no Inertia page yet: resolve the shared prop outside any tenant context.
    app(TenantContext::class)->forget();
    $request = Request::create('/platform');
    $request->setUserResolver(static fn (): User => $platform);
    $roles = app(HandleInertiaRequests::class)->share($request)['auth']['roles'];

    expect($roles())->toBe([]);
});

it('shares today\'s net sales and invoice count for the current branch, only with dashboard.view', function (): void {
    $this->withoutVite();
    Cache::flush();
    $world = CatalogWorld::make();
    $second = app(TenantContext::class)->withoutTenancy(fn () => Branch::factory()->for($world->tenant)->create());
    $other = CatalogWorld::make();
    app(TenantContext::class)->withoutTenancy(function () use ($world, $second, $other): void {
        $sale = static fn (CatalogWorld $w, Branch $branch, int $total, array $extra = []): Sale => Sale::factory()->create([
            'tenant_id' => $w->tenant->id, 'branch_id' => $branch->id, 'sold_at' => now(), 'total' => Money::ofPaisa($total), ...$extra,
        ]);
        $sale($world, $world->branch, 120000, ['returned_total' => Money::ofPaisa(20000)]);
        $sale($world, $world->branch, 50000);
        $sale($world, $world->branch, 99000, ['status' => 'voided']);
        $sale($world, $world->branch, 77000, ['sold_at' => now()->subDays(2)]);
        $sale($world, $second, 33000);
        $sale($other, $other->branch, 88000);
    });

    $this->actingAs($world->user)->withSession(['branch_id' => $world->branch->id])->get(route('dashboard'))
        ->assertInertia(fn (Assert $page) => $page->where('todayTicker', ['total_paisa' => 150000, 'sales' => 2]));

    $keeper = CatalogWorld::make(SystemRoles::STOCK_KEEPER);
    $this->actingAs($keeper->user)->withSession(['branch_id' => $keeper->branch->id])->get('/app/stock')
        ->assertInertia(fn (Assert $page) => $page->where('todayTicker', null));
});

it('embeds the branch id in the inertia version so a switch drops old-branch page cache', function (): void {
    $world = CatalogWorld::make();
    $owner = $world->user;
    $second = app(TenantContext::class)->withoutTenancy(fn () => Branch::factory()->for($world->tenant)->create());
    $owner->branches()->syncWithoutDetaching([$world->branch->id, $second->id]);

    $data = $this->actingAs($owner)->withSession(['branch_id' => $world->branch->id])->get(route('dashboard'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('auth.branch_id', $world->branch->id))
        ->original->getData()['page'];
    $oldVersion = $data['version'];

    $this->actingAs($owner)->post("/app/branch/{$second->id}/switch")->assertRedirect(route('dashboard'));

    $data = $this->actingAs($owner)->get(route('dashboard'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('auth.branch_id', $second->id))
        ->original->getData()['page'];
    expect($data['version'])->not->toBe($oldVersion)
        ->and($data['version'])->toContain('branch-'.$second->id);
});
