<?php

declare(strict_types=1);

use App\Actions\Sales\CollectCustomerPayment;
use App\Actions\Sales\CreateSaleReturn;
use App\Actions\Sales\Data\CustomerPaymentDraft;
use App\Actions\Sales\Data\ReturnDraft;
use App\Actions\Sales\Data\ReturnLineDraft;
use App\Enums\RefundMode;
use App\Models\Branch;
use App\Models\Customer;
use App\Models\Sale;
use App\Models\Tenant;
use App\Reports\Dashboard\DashboardAggregates;
use App\Reports\Dashboard\DashboardPeriod;
use App\Reports\DashboardQuery;
use App\Reports\ReportParameters;
use App\Support\Money\Money;
use App\Support\Quantity\Quantity;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Spatie\Permission\PermissionRegistrar;
use Tests\Fixtures\SalesWorld;

beforeEach(function (): void {
    // 14:00 in Dhaka, Tuesday 3 November 2026
    $this->travelTo(Carbon::parse('2026-11-03 08:00:00', 'UTC'));
    $this->world = SalesWorld::make();
    $this->product = $this->world->product(100_000, 60_000, ['reorder_level' => Quantity::ofUnits(10)]);
    $this->world->stock($this->product, 10, 60_000, 60 * 48);
    $this->alice = $this->world->customer(['name' => 'Alice']);
    $this->bob = $this->world->customer(['name' => 'Bob']);

    // yesterday 12:00 local: one cash sale
    $this->world->sell([$this->world->line($this->product, 1)], [$this->world->tender('cash', 100_000)], $this->alice, soldAt: Carbon::parse('2026-11-02 06:00:00', 'UTC'));
    // today 12:00 local: two units cash; 13:00 local: one unit on credit, half collected later
    $this->aliceToday = $this->world->sell([$this->world->line($this->product, 2)], [$this->world->tender('cash', 200_000)], $this->alice, soldAt: Carbon::parse('2026-11-03 06:00:00', 'UTC'));
    $this->world->sell([$this->world->line($this->product, 1)], [], $this->bob, soldAt: Carbon::parse('2026-11-03 07:00:00', 'UTC'));
    app(CollectCustomerPayment::class)->handle($this->world->user(), new CustomerPaymentDraft($this->bob->id, Money::ofPaisa(50_000), $this->world->methods['bkash']->id, reference: 'TX1'));

    foreach ($this->world->users as $user) {
        $user->branches()->attach($this->world->branch);
    }
    $this->second = Branch::factory()->for($this->world->tenant)->create(['code' => 'DF2', 'name' => 'Dhanmondi']);
    $this->world->users['Owner']->branches()->attach($this->second);
    app(TenantContext::class)->withoutTenancy(fn () => Sale::factory()->create([
        'tenant_id' => $this->world->tenant->id, 'branch_id' => $this->second->id, 'customer_id' => $this->bob->id,
        'sold_at' => Carbon::parse('2026-11-03 05:00:00', 'UTC'), 'total' => Money::ofPaisa(70_000), 'status' => 'completed',
    ]));

    app(PermissionRegistrar::class)->setPermissionsTeamId($this->world->tenant->id);
    $this->withSession(['branch_id' => $this->world->branch->id]);
    Cache::flush();
});

afterEach(function (): void {
    app(TenantContext::class)->forget();
});

it('resolves each period and the window it is compared with', function (string $key, string $from, string $to, string $prevFrom, string $prevTo): void {
    $period = DashboardPeriod::resolve($key, CarbonImmutable::parse('2026-03-31 15:00:00'));

    expect([$period->from, $period->to, $period->previousFrom, $period->previousTo])->toBe([$from, $to, $prevFrom, $prevTo]);
})->with([
    'today' => ['today', '2026-03-31', '2026-03-31', '2026-03-30', '2026-03-30'],
    'yesterday' => ['yesterday', '2026-03-30', '2026-03-30', '2026-03-29', '2026-03-29'],
    '7 days' => ['7d', '2026-03-25', '2026-03-31', '2026-03-18', '2026-03-24'],
    '30 days' => ['30d', '2026-03-02', '2026-03-31', '2026-01-31', '2026-03-01'],
    'this month (capped at the end of a short month)' => ['this_month', '2026-03-01', '2026-03-31', '2026-02-01', '2026-02-28'],
    'unknown key falls back to today' => ['nope', '2026-03-31', '2026-03-31', '2026-03-30', '2026-03-30'],
]);

it('resolves a custom range, swapping, capping and rejecting bad dates', function (): void {
    $today = CarbonImmutable::parse('2026-11-03');

    $swapped = DashboardPeriod::resolve('custom', $today, '2026-10-10', '2026-10-01');
    expect([$swapped->from, $swapped->to, $swapped->previousFrom, $swapped->previousTo])->toBe(['2026-10-01', '2026-10-10', '2026-09-21', '2026-09-30']);

    $capped = DashboardPeriod::resolve('custom', $today, '2020-01-01', '2026-11-03');
    expect($capped->days())->toBe(DashboardPeriod::MAX_DAYS);

    $bad = DashboardPeriod::resolve('custom', $today, '2026-02-30', 'x');
    expect($bad->key)->toBe('today')->and($bad->from)->toBe('2026-11-03');
});

it('computes the nine KPIs with the previous period for the current branch', function (): void {
    $this->actingAs($this->world->user('Owner'))->get(route('dashboard'))->assertOk()
        ->assertInertia(fn ($page) => $page->component('dashboard')
            ->where('filters.period', 'today')
            ->where('filters.branch', 'current')
            ->where('dashboard.kpis.sales.value', 300_000)
            ->where('dashboard.kpis.sales.previous', 100_000)
            ->where('dashboard.kpis.invoices.value', 2)
            ->where('dashboard.kpis.invoices.previous', 1)
            ->where('dashboard.kpis.average.value', 150_000)
            ->where('dashboard.kpis.profit.value', 300_000 - 3 * 60_000)
            ->where('dashboard.kpis.profit.previous', 40_000)
            ->where('dashboard.kpis.collections.value', 50_000)
            ->where('dashboard.kpis.collections.previous', 0)
            ->where('dashboard.kpis.dues.value', 50_000)
            ->where('dashboard.kpis.dues.debtors', 1)
            ->where('dashboard.kpis.returns.value', 0)
            ->where('dashboard.kpis.stock_value.value', 6 * 60_000)
            ->where('dashboard.kpis.stock_value.skus', 1)
            ->where('dashboard.kpis.low_stock.value', 1)
            ->where('dashboard.low_stock.0.cost', 60_000)
            ->where('dashboard.debtors.0.customer', 'Bob')
            ->where('dashboard.debtors.0.uuid', fn (?string $uuid): bool => is_string($uuid) && Customer::query()->where('uuid', $uuid)->where('name', 'Bob')->exists())
            ->where('dashboard.debtors.0.due', 50_000)
            ->where('dashboard.recent_sales.0.customer', 'Bob')
            ->where('dashboard.top_customers.0.customer', 'Alice')
            ->where('dashboard.top_products.0.sales', 300_000));
});

it('splits the payment mix by method and keeps sale tenders out of collections', function (): void {
    $this->actingAs($this->world->user('Owner'))->get(route('dashboard'))
        ->assertInertia(fn ($page) => $page
            ->where('dashboard.payment_mix', [
                ['method' => 'Cash', 'type' => 'cash', 'amount' => 200_000],
                ['method' => 'bKash', 'type' => 'mobile_wallet', 'amount' => 50_000],
            ]));
});

it('draws the trend hourly for one day and daily for longer periods', function (): void {
    $this->actingAs($this->world->user('Owner'))->get(route('dashboard'))
        ->assertInertia(fn ($page) => $page
            ->where('dashboard.trend.granularity', 'hour')
            ->has('dashboard.trend.points', 24)
            ->where('dashboard.trend.points.12', ['key' => '12', 'net' => 200_000, 'previous' => 100_000])
            ->where('dashboard.trend.points.13.net', 100_000));

    $this->actingAs($this->world->user('Owner'))->get(route('dashboard', ['period' => '7d']))
        ->assertInertia(fn ($page) => $page
            ->where('filters.period', '7d')
            ->where('dashboard.trend.granularity', 'day')
            ->has('dashboard.trend.points', 7)
            ->where('dashboard.trend.points.5', ['key' => '2026-11-02', 'net' => 100_000, 'previous' => 0])
            ->where('dashboard.trend.points.6.net', 300_000));
});

it('counts sales per weekday and hour over at least four weeks for the heat-map', function (): void {
    $this->actingAs($this->world->user('Owner'))->get(route('dashboard'))
        ->assertInertia(fn ($page) => $page
            ->where('dashboard.heatmap.from', '2026-10-07')
            ->where('dashboard.heatmap.to', '2026-11-03')
            ->where('dashboard.heatmap.cells', [
                ['dow' => 1, 'hour' => 12, 'count' => 1],
                ['dow' => 2, 'hour' => 12, 'count' => 1],
                ['dow' => 2, 'hour' => 13, 'count' => 1],
            ]));
});

it('reports returns of the period', function (): void {
    $sale = $this->aliceToday->load('items');
    app(CreateSaleReturn::class)->handle(
        $this->world->user('Manager'),
        new ReturnDraft($sale->id, [new ReturnLineDraft($sale->items[0]->id, Quantity::ofUnits(1))], RefundMode::Refund, 'size'),
    );

    $this->actingAs($this->world->user('Owner'))->get(route('dashboard'))
        ->assertInertia(fn ($page) => $page
            ->where('dashboard.kpis.returns.value', 100_000)
            ->where('dashboard.kpis.returns.count', 1)
            ->where('dashboard.kpis.sales.value', 200_000));
});

it('lets reports.all_branches pick all branches or another branch and compare branches', function (): void {
    $owner = $this->world->user('Owner');

    $this->actingAs($owner)->get(route('dashboard', ['branch' => 'all']))
        ->assertInertia(fn ($page) => $page
            ->where('filters.branch', 'all')
            ->where('can.allBranches', true)
            ->has('branches', 2)
            ->where('dashboard.kpis.sales.value', 370_000)
            ->where('dashboard.branches.0', ['id' => $this->world->branch->id, 'code' => 'DF1', 'name' => $this->world->branch->name, 'sales' => 300_000, 'previous' => 100_000, 'profit' => 120_000])
            ->where('dashboard.branches.1.sales', 70_000));

    $this->actingAs($owner)->get(route('dashboard', ['branch' => (string) $this->second->id]))
        ->assertInertia(fn ($page) => $page
            ->where('filters.branch', (string) $this->second->id)
            ->where('dashboard.kpis.sales.value', 70_000)
            ->where('dashboard.kpis.invoices.value', 1));
});

it('keeps a user without reports.all_branches on the current branch and hides the comparison', function (): void {
    $this->actingAs($this->world->user('Manager'))->get(route('dashboard', ['branch' => 'all']))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('filters.branch', 'current')
            ->where('can.allBranches', false)
            ->where('branches', [])
            ->where('dashboard.branches', null)
            ->where('dashboard.kpis.sales.value', 300_000));

    $this->actingAs($this->world->user('Manager'))->get(route('dashboard', ['branch' => (string) $this->second->id]))
        ->assertInertia(fn ($page) => $page->where('filters.branch', 'current')->where('dashboard.kpis.sales.value', 300_000));
});

it('strips cost and profit figures without reports.profit', function (): void {
    $this->actingAs($this->world->user('Cashier'))->get(route('dashboard'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('can.profit', false)
            ->missing('dashboard.kpis.profit')
            ->missing('dashboard.kpis.stock_value')
            ->missing('dashboard.low_stock.0.cost')
            ->where('dashboard.kpis.sales.value', 300_000));
});

it('never counts another tenant\'s sales, even for all branches', function (): void {
    app(TenantContext::class)->withoutTenancy(function (): void {
        $other = Tenant::factory()->create();
        $branch = Branch::factory()->for($other)->create(['code' => 'X1']);
        $customer = Customer::factory()->create(['tenant_id' => $other->id]);
        Sale::factory()->create([
            'tenant_id' => $other->id, 'branch_id' => $branch->id, 'customer_id' => $customer->id,
            'sold_at' => Carbon::parse('2026-11-03 06:00:00', 'UTC'), 'total' => Money::ofPaisa(999_000), 'status' => 'completed',
        ]);
    });

    $this->actingAs($this->world->user('Owner'))->get(route('dashboard', ['branch' => 'all']))
        ->assertInertia(fn ($page) => $page
            ->where('dashboard.kpis.sales.value', 370_000)
            ->has('dashboard.branches', 2)
            ->has('branches', 2));
});

it('caches per tenant, branch scope and period', function (): void {
    $this->actingAs($this->world->user('Owner'))->get(route('dashboard'))->assertOk();

    $today = DashboardPeriod::resolve('today', CarbonImmutable::parse('2026-11-03'));
    $week = DashboardPeriod::resolve('7d', CarbonImmutable::parse('2026-11-03'));
    $tenant = $this->world->tenant->id;
    $branch = $this->world->branch->id;

    expect(Cache::has(DashboardQuery::cacheKey($tenant, $branch, $today, true)))->toBeTrue()
        ->and(Cache::has(DashboardQuery::cacheKey($tenant, null, $today, true)))->toBeFalse()
        ->and(Cache::has(DashboardQuery::cacheKey($tenant, $branch, $week, true)))->toBeFalse()
        ->and(DashboardQuery::cacheKey($tenant, $branch, $today))->not->toBe(DashboardQuery::cacheKey($tenant + 1, $branch, $today));
});

it('reports the first-run checklist from real state', function (): void {
    $this->actingAs($this->world->user('Owner'))->get(route('dashboard'))
        ->assertInertia(fn ($page) => $page->where('dashboard.setup', [
            'logo' => false, 'payment_methods' => true, 'products' => true, 'customers' => true, 'first_sale' => true,
        ]));
});

function ensureDistricts(): array
{
    $divisionId = DB::table('geo_divisions')->insertGetId(['code' => 'T1', 'name_en' => 'Testland', 'name_bn' => 'টেস্টল্যান্ড']);
    $a = DB::table('geo_districts')->insertGetId(['division_id' => $divisionId, 'code' => 'T1-1', 'name_en' => 'Testpur', 'name_bn' => 'টেস্টপুর']);
    $b = DB::table('geo_districts')->insertGetId(['division_id' => $divisionId, 'code' => 'T1-2', 'name_en' => 'Demoganj', 'name_bn' => 'ডেমোগঞ্জ']);

    return [$a, $b];
}

it('splits net sales by customer district and sums to the Net Sales KPI with Not set', function (): void {
    [$testpur] = ensureDistricts();
    $this->alice->update(['geo_district_id' => $testpur]); // Bob has no location

    $this->actingAs($this->world->user('Owner'))->get(route('dashboard'))->assertInertia(fn ($page) => $page
        ->where('dashboard.kpis.sales.value', 300_000)
        ->where('dashboard.districts.rows.0.name_en', 'Testpur')
        ->where('dashboard.districts.rows.0.name_bn', 'টেস্টপুর')
        ->where('dashboard.districts.rows.0.sales', 200_000)
        ->where('dashboard.districts.unset', ['sales' => 100_000, 'invoices' => 1])
        ->where('dashboard.districts.others.districts', 0)
        ->where('dashboard.districts.total', 300_000));
});

it('puts every sale in Not set when no customer has a district, and folds small districts into Others', function (): void {
    $this->actingAs($this->world->user('Owner'))->get(route('dashboard'))->assertInertia(fn ($page) => $page
        ->where('dashboard.districts.rows', [])
        ->where('dashboard.districts.unset.sales', 300_000)
        ->where('dashboard.districts.total', 300_000));

    [$testpur, $demoganj] = ensureDistricts();
    $this->alice->update(['geo_district_id' => $testpur]);
    $this->bob->update(['geo_district_id' => $demoganj]);
    $rows = app(DashboardAggregates::class)->salesByDistrict(
        new ReportParameters('2026-11-03', '2026-11-03', 'Asia/Dhaka', [$this->world->branch->id]),
        limit: 1,
    );

    expect($rows['rows'])->toHaveCount(1)
        ->and($rows['rows'][0]['name_en'])->toBe('Testpur')
        ->and($rows['others'])->toBe(['districts' => 1, 'sales' => 100_000, 'invoices' => 1])
        ->and($rows['unset'])->toBe(['sales' => 0, 'invoices' => 0])
        ->and($rows['total'])->toBe(300_000);
});

it('keeps district sales within the branch scope, the period and the tenant', function (): void {
    [$testpur] = ensureDistricts();
    $this->alice->update(['geo_district_id' => $testpur]);
    $this->bob->update(['geo_district_id' => $testpur]);
    app(TenantContext::class)->withoutTenancy(function () use ($testpur): void {
        $other = Tenant::factory()->create();
        $branch = Branch::factory()->for($other)->create(['code' => 'X1']);
        $customer = Customer::factory()->create(['tenant_id' => $other->id, 'geo_district_id' => $testpur]);
        Sale::factory()->create([
            'tenant_id' => $other->id, 'branch_id' => $branch->id, 'customer_id' => $customer->id,
            'sold_at' => Carbon::parse('2026-11-03 06:00:00', 'UTC'), 'total' => Money::ofPaisa(999_000), 'status' => 'completed',
        ]);
    });

    $owner = $this->world->user('Owner');
    $this->actingAs($owner)->get(route('dashboard', ['branch' => 'all']))->assertInertia(fn ($page) => $page
        ->where('dashboard.districts.total', 370_000)
        ->where('dashboard.districts.rows.0.sales', 370_000));
    $this->actingAs($owner)->get(route('dashboard', ['period' => 'yesterday']))->assertInertia(fn ($page) => $page
        ->where('dashboard.districts.total', 100_000)
        ->where('dashboard.kpis.sales.value', 100_000));
});

it('falls back to the current branch when branch= points at an inactive branch', function (): void {
    $inactive = Branch::factory()->for($this->world->tenant)->create(['code' => 'DF9', 'is_active' => false]);
    $owner = $this->world->user('Owner');

    $this->actingAs($owner)->get(route('dashboard', ['branch' => (string) $inactive->id]))
        ->assertInertia(fn ($page) => $page
            ->where('filters.branch', 'current')
            ->where('dashboard.kpis.sales.value', 300_000));
});
