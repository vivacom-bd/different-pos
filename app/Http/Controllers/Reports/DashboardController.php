<?php

declare(strict_types=1);

namespace App\Http\Controllers\Reports;

use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Models\User;
use App\Reports\Dashboard\DashboardPeriod;
use App\Reports\DashboardQuery;
use App\Support\Sales\BusinessDay;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The dashboard: `period` (today, yesterday, 7d, 30d, this_month, custom + from/to) and `branch`
 * (`current`, `all` or a branch id; anything but the current branch needs reports.all_branches,
 * which also adds the branch comparison). Cost and profit figures need reports.profit.
 */
final class DashboardController extends Controller
{
    public function __invoke(Request $request, DashboardQuery $query, TenantContext $context): Response
    {
        Gate::authorize('dashboard.view');

        $user = $request->user();
        $canAll = $user instanceof User && $user->can('reports.all_branches');
        $canProfit = $user instanceof User && $user->can('reports.profit');

        $periodKey = $request->string('period')->toString();
        $period = DashboardPeriod::resolve(
            in_array($periodKey, DashboardPeriod::KEYS, true) ? $periodKey : DashboardPeriod::TODAY,
            CarbonImmutable::now(BusinessDay::timezone($context->tenant())),
            $request->string('from')->toString(),
            $request->string('to')->toString(),
        );

        [$branchKey, $branchId] = $this->branch($request->string('branch')->toString(), $canAll, $context);
        $data = $query->get($period, $branchId, $canAll);
        if (! $canProfit) {
            $data = $this->withoutProfit($data);
        }

        return Inertia::render('dashboard', [
            'dashboard' => $data,
            'filters' => ['period' => $period->key, 'from' => $period->from, 'to' => $period->to, 'branch' => $branchKey],
            'branches' => $canAll
                ? Branch::query()->where('is_active', true)->orderBy('code')->get(['id', 'code', 'name'])->map(static fn (Branch $b): array => ['id' => $b->id, 'code' => $b->code, 'name' => $b->name])->all()
                : [],
            'currentBranch' => ['id' => $context->branchId(), 'code' => $context->branch()->code, 'name' => $context->branch()->name],
            'can' => ['allBranches' => $canAll, 'profit' => $canProfit],
        ]);
    }

    /** @return array{0: string, 1: int|null} the branch key echoed to the page and the scope */
    private function branch(string $choice, bool $canAll, TenantContext $context): array
    {
        if ($canAll && $choice === 'all') {
            return ['all', null];
        }
        if ($canAll && ctype_digit($choice) && Branch::query()->whereKey((int) $choice)->where('is_active', true)->exists()) {
            return [$choice, (int) $choice];
        }

        return ['current', $context->branchId()];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function withoutProfit(array $data): array
    {
        $kpis = is_array($data['kpis'] ?? null) ? $data['kpis'] : [];
        unset($kpis['profit'], $kpis['stock_value']);
        $data['kpis'] = $kpis;
        if (is_array($data['low_stock'] ?? null)) {
            $data['low_stock'] = array_map(static function (mixed $row): mixed {
                if (is_array($row)) {
                    unset($row['cost']);
                }

                return $row;
            }, $data['low_stock']);
        }
        if (is_array($data['branches'] ?? null)) {
            $data['branches'] = array_map(static function (mixed $row): mixed {
                if (is_array($row)) {
                    unset($row['profit']);
                }

                return $row;
            }, $data['branches']);
        }

        return $data;
    }
}
