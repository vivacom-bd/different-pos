<?php

declare(strict_types=1);

namespace Tests\Fixtures;

use App\Actions\Sales\CompleteSale;
use App\Actions\Sales\Data\Discount;
use App\Actions\Sales\Data\SaleDraft;
use App\Actions\Sales\Data\SaleLineDraft;
use App\Actions\Sales\Data\TenderDraft;
use App\Actions\Tenancy\SeedTenantRoles;
use App\Enums\PaymentMethodType;
use App\Enums\StockMovementType;
use App\Models\Branch;
use App\Models\Customer;
use App\Models\PaymentMethod;
use App\Models\Product;
use App\Models\Sale;
use App\Models\StockBatch;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Database\Transaction;
use App\Support\Money\Money;
use App\Support\Quantity\Quantity;
use App\Support\Stock\StockLedger;
use App\Support\Tenancy\TenantContext;
use Spatie\Permission\PermissionRegistrar;

/**
 * A tenant + branch with the context set, the five system roles, one user per role, a walk-in
 * customer and the seeded payment methods, plus helpers for sales tests.
 */
final readonly class SalesWorld
{
    /**
     * @param  array<string, User>  $users
     * @param  array<string, PaymentMethod>  $methods
     */
    private function __construct(
        public Tenant $tenant,
        public Branch $branch,
        public array $users,
        public Customer $walkIn,
        public array $methods,
    ) {}

    /**
     * @param  array<string, mixed>  $tenantSettings
     * @param  array<string, mixed>  $branchSettings
     */
    public static function make(array $tenantSettings = [], array $branchSettings = []): self
    {
        $context = app(TenantContext::class);
        [$tenant, $branch] = $context->withoutTenancy(static function () use ($tenantSettings, $branchSettings): array {
            $tenant = Tenant::factory()->create(['settings' => ['tax_inclusive' => false, 'require_customer_on_sale' => false, ...$tenantSettings]]);

            return [$tenant, Branch::factory()->for($tenant)->create(['code' => 'DF1', 'settings' => $branchSettings])];
        });
        $context->set($tenant, $branch);

        app(SeedTenantRoles::class)->handle($tenant);
        app(PermissionRegistrar::class)->setPermissionsTeamId($tenant->id);

        $users = [];
        foreach (['Owner', 'Manager', 'Cashier', 'Accountant', 'Stock Keeper'] as $role) {
            $user = $context->withoutTenancy(static fn (): User => User::factory()->create(['tenant_id' => $tenant->id]));
            $user->assignRole($role);
            $users[$role] = $user;
        }

        $walkIn = Customer::factory()->create(['tenant_id' => $tenant->id, 'name' => 'Walk-in', 'phone' => null, 'is_walk_in' => true]);

        $methods = [];
        foreach ([
            'cash' => ['Cash', PaymentMethodType::Cash, false],
            'card' => ['Card', PaymentMethodType::Card, false],
            'bkash' => ['bKash', PaymentMethodType::MobileWallet, true],
            'credit' => ['Customer credit', PaymentMethodType::CustomerCredit, false],
            'exchange' => ['Exchange credit', PaymentMethodType::ExchangeCredit, false],
        ] as $key => [$name, $type, $reference]) {
            $methods[$key] = PaymentMethod::factory()->create([
                'tenant_id' => $tenant->id, 'name' => $name, 'type' => $type, 'requires_reference' => $reference, 'sort_order' => count($methods),
            ]);
        }

        return new self($tenant, $branch, $users, $walkIn, $methods);
    }

    public function user(string $role = 'Owner'): User
    {
        return $this->users[$role];
    }

    /** @param  array<string, mixed>  $attributes */
    public function product(int $price = 100_000, int $cost = 0, array $attributes = []): Product
    {
        return Product::factory()->create([
            'tenant_id' => $this->tenant->id,
            'sale_price' => Money::ofPaisa($price),
            'cost_price' => Money::ofPaisa($cost),
            ...$attributes,
        ])->refresh();
    }

    /** @param  array<string, mixed>  $attributes */
    public function customer(array $attributes = []): Customer
    {
        return Customer::factory()->create(['tenant_id' => $this->tenant->id, ...$attributes])->refresh();
    }

    /** Puts a batch on the shelf. */
    public function stock(Product $product, int|string $units, int $unitCost, int $minutesAgo = 60): StockBatch
    {
        return Transaction::run(fn (): StockBatch => app(StockLedger::class)->receive(
            $product,
            $this->branch,
            is_int($units) ? Quantity::ofUnits($units) : Quantity::parse($units),
            Money::ofPaisa($unitCost),
            StockMovementType::Opening,
            null,
            ['type' => 'test', 'id' => 1],
            now()->subMinutes($minutesAgo),
        ));
    }

    public function line(Product $product, int|string $units = 1, ?int $price = null, ?Discount $discount = null): SaleLineDraft
    {
        return new SaleLineDraft(
            $product->id,
            is_int($units) ? Quantity::ofUnits($units) : Quantity::parse($units),
            $price === null ? null : Money::ofPaisa($price),
            $discount,
        );
    }

    public function tender(string $method, int $paisa, ?string $reference = null): TenderDraft
    {
        return new TenderDraft($this->methods[$method]->id, Money::ofPaisa($paisa), $reference);
    }

    /**
     * Completes a sale as $role.
     *
     * @param  list<SaleLineDraft>  $lines
     * @param  list<TenderDraft>  $tenders
     */
    public function sell(array $lines, array $tenders = [], ?Customer $customer = null, string $role = 'Owner', mixed ...$options): Sale
    {
        return app(CompleteSale::class)->handle($this->user($role), new SaleDraft(...[
            'lines' => $lines,
            'tenders' => $tenders,
            'customerId' => $customer?->id,
            ...$options,
        ]));
    }
}
