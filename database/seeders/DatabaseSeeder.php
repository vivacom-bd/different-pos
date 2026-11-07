<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Actions\Settings\EnsureSystemPaymentMethods;
use App\Enums\PaymentMethodType;
use App\Models\Branch;
use App\Models\Customer;
use App\Models\PaymentMethod;
use App\Models\Register;
use App\Models\Tenant;
use App\Models\Unit;
use App\Models\User;
use App\Support\Customers\CustomerNumber;
use App\Support\Database\Transaction;
use App\Support\Permissions\SystemRoles;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\Seeder;
use Spatie\Permission\PermissionRegistrar;

final class DatabaseSeeder extends Seeder
{
    private const string STORE_PASSWORD = 'abc123AB';

    public function run(): void
    {
        $store = $this->tenant('different-fashion', 'DIFFERENT FASHION', '+8801974202796');
        $main = $this->branch($store, 'DF1', 'Main', ['paper' => 'a4']);
        $second = $this->branch($store, 'DF2', 'Branch 2', ['paper' => 'a4']);

        // Demo data backs the tests and browser checks, and its weak logins must not reach a shared server.
        $demo = app()->environment(['local', 'testing']) ? $this->tenant('demo-store', 'Demo Store') : null;
        $demoBranch = $demo instanceof Tenant ? $this->branch($demo, 'MAIN', 'Main Branch') : null;

        $this->call(RolesAndPermissionsSeeder::class);
        $this->call(GeoSeeder::class);

        // Real customers, products, stock, vendors, purchases and sales from the legacy dump (once).
        $this->call(DifferentFashionSeeder::class);
        $this->storeEssentials($store, [$main, $second]);

        $this->tenantUser($store, ['email' => 'owner@differentfashion.test'], 'Owner', self::STORE_PASSWORD, SystemRoles::OWNER, [$main->id => true, $second->id => false], 'owner');
        $this->tenantUser($store, ['username' => 'Different -1'], 'Different -1', self::STORE_PASSWORD, SystemRoles::MANAGER, [$main->id => true], 'Different -1');
        $this->tenantUser($store, ['username' => 'Different -2'], 'Different -2', self::STORE_PASSWORD, SystemRoles::MANAGER, [$second->id => true], 'Different -2');
        $this->platformAdmin('admin@differentfashion.test', self::STORE_PASSWORD);

        if ($demo instanceof Tenant && $demoBranch instanceof Branch) {
            $this->tenantUser($demo, ['email' => 'owner@demo.test'], 'Demo Owner', 'password', SystemRoles::OWNER, [$demoBranch->id => true]);
            $this->platformAdmin('admin@demo.test', 'password');
        }
    }

    /**
     * What a new business needs before its first sale, as in ProvisionTenant.
     *
     * @param  list<Branch>  $branches
     */
    private function storeEssentials(Tenant $tenant, array $branches): void
    {
        Transaction::run(function () use ($tenant, $branches): void {
            foreach ($branches as $branch) {
                app(TenantContext::class)->run($tenant, $branch, function () use ($tenant, $branch): void {
                    if (! Register::query()->where('code', 'R1')->exists()) {
                        $register = new Register;
                        $register->forceFill(['tenant_id' => $tenant->id, 'branch_id' => $branch->id, 'code' => 'R1', 'name' => 'R1', 'number_year' => (int) now()->year, 'is_active' => true])->save();
                    }
                });
            }

            app(TenantContext::class)->run($tenant, $branches[0], function (): void {
                PaymentMethod::query()->firstOrCreate(['type' => PaymentMethodType::Cash], ['name' => 'Cash', 'requires_reference' => false, 'sort_order' => 1, 'is_active' => true]);
                Unit::query()->firstOrCreate(['short_name' => 'pcs'], ['name' => 'Piece', 'allows_decimal' => false, 'is_active' => true]);
                // Fabric and other raw materials are bought by length or weight.
                foreach (['m' => 'Metre', 'yd' => 'Yard', 'kg' => 'Kilogram'] as $short => $name) {
                    Unit::query()->firstOrCreate(['short_name' => $short], ['name' => $name, 'allows_decimal' => true, 'is_active' => true]);
                }

                if (! Customer::query()->where('is_walk_in', true)->exists()) {
                    $walkIn = new Customer;
                    $walkIn->forceFill(['name' => 'Walk-in customer', 'is_walk_in' => true, 'is_active' => true, 'customer_no' => (int) app(CustomerNumber::class)->allocate()])->save();
                }
            });
        });
    }

    private function tenant(string $slug, string $name, ?string $phone = null): Tenant
    {
        $tenant = Tenant::query()->firstOrCreate(['slug' => $slug], [
            'name' => $name,
            'phone' => $phone,
            'locale' => 'en',
            'timezone' => 'Asia/Dhaka',
            'currency' => 'BDT',
        ]);

        // Store credit and exchange credit tenders (returns, exchanges, customer accounts).
        app(TenantContext::class)->withoutTenancy(fn (): int => app(EnsureSystemPaymentMethods::class)->handle($tenant));

        return $tenant;
    }

    /** @param array<string, mixed> $settings */
    private function branch(Tenant $tenant, string $code, string $name, array $settings = []): Branch
    {
        return app(TenantContext::class)->withoutTenancy(
            fn (): Branch => Branch::query()->firstOrCreate(
                ['tenant_id' => $tenant->id, 'code' => $code],
                ['name' => $name, 'is_active' => true, 'settings' => $settings],
            ),
        );
    }

    /**
     * @param  array<string, string>  $identity
     * @param  array<int, bool>  $branches  branch id => is default
     */
    private function tenantUser(Tenant $tenant, array $identity, string $name, string $password, string $role, array $branches, ?string $username = null): void
    {
        $user = User::query()->where('tenant_id', $tenant->id)->where($identity)->first() ?? new User($identity);
        $user->forceFill($identity + [
            'name' => $name,
            'username' => $username,
            'password' => $password,
            'tenant_id' => $tenant->id,
            'email_verified_at' => now(),
            'is_active' => true,
        ])->save();

        $user->branches()->syncWithoutDetaching(
            array_map(static fn (bool $default): array => ['is_default' => $default], $branches),
        );

        app(PermissionRegistrar::class)->setPermissionsTeamId($tenant->id);
        $user->assignRole($role);
    }

    private function platformAdmin(string $email, string $password): void
    {
        $admin = User::query()->firstOrNew(['email' => $email]);
        $admin->forceFill([
            'name' => 'Platform Admin',
            'password' => $password,
            'is_platform_admin' => true,
            'email_verified_at' => now(),
            'is_active' => true,
        ])->save();
    }
}
