<?php

declare(strict_types=1);

namespace App\Http\Controllers\Vendors;

use App\Actions\Purchasing\PayVendor;
use App\Actions\Vendors\DeactivateVendor;
use App\Actions\Vendors\SaveVendor;
use App\Enums\AllocatableType;
use App\Enums\PartyType;
use App\Enums\PaymentDirection;
use App\Enums\PurchaseStatus;
use App\Http\Controllers\Concerns\TranslatesDomainErrors;
use App\Http\Controllers\Controller;
use App\Http\Requests\Vendors\PayVendorRequest;
use App\Http\Requests\Vendors\SaveVendorRequest;
use App\Models\Payment;
use App\Models\PaymentMethod;
use App\Models\Purchase;
use App\Models\User;
use App\Models\Vendor;
use App\Reports\ColumnType;
use App\Reports\ReportColumn;
use App\Reports\ReportResult;
use App\Support\Export\ExportFormat;
use App\Support\Export\ExportMeta;
use App\Support\Export\TabularExport;
use App\Support\Http\RecencyFilter;
use App\Support\Http\TableQuery;
use App\Support\Money\Money;
use App\Support\Sales\BusinessDay;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Query\Builder;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response as HttpResponse;
use Illuminate\Support\Carbon;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class VendorController extends Controller
{
    use AuthorizesRequests;
    use TranslatesDomainErrors;

    public function index(Request $request, TenantContext $context): Response
    {
        $this->authorize('viewAny', Vendor::class);
        $table = $this->table($request);
        $tz = BusinessDay::timezone($context->tenant());
        $query = $this->filtered($table, $tz);
        $totals = (clone $query)->toBase()->reorder()->selectRaw('coalesce(sum(case when balance > 0 then balance else 0 end), 0) as payable')->first();
        $openBills = Purchase::query()->withoutBranchScope()->where('status', PurchaseStatus::Received->value)->where('due', '>', 0)
            ->whereIn('vendor_id', (clone $query)->toBase()->reorder()->select('id'))->count();
        $page = $table->paginate($query);

        // Open bills and last order across the tenant's branches (a vendor is tenant-wide, like its balance).
        /** @var array<int, array{open: int, last: string|null}> $bills */
        $bills = [];
        $grouped = Purchase::query()->withoutBranchScope()->where('status', PurchaseStatus::Received->value)
            ->whereIn('vendor_id', collect($page->items())->pluck('id')->all())
            ->groupBy('vendor_id')
            ->toBase()
            ->selectRaw('vendor_id, sum(due > 0) as open_bills, max(purchased_at) as last_order')
            ->get();
        foreach ($grouped as $g) {
            $bills[(int) $g->vendor_id] = ['open' => (int) $g->open_bills, 'last' => $g->last_order !== null ? BusinessDay::localDate(Carbon::parse((string) $g->last_order, 'UTC'), $tz) : null];
        }
        $user = $this->actor($request);

        return Inertia::render('vendors/index', [
            'rows' => collect($page->items())->map(fn (Vendor $v): array => $this->row($v) + [
                'open_bills' => $bills[$v->id]['open'] ?? 0,
                'last_order' => $bills[$v->id]['last'] ?? null,
            ])->all(),
            'meta' => ['page' => $page->currentPage(), 'perPage' => $page->perPage(), 'total' => $page->total(), 'lastPage' => $page->lastPage()],
            'totals' => ['payable' => (int) ($totals->payable ?? 0), 'open_bills' => $openBills],
            'hubs' => $this->hubs(),
            'paymentMethods' => PaymentMethod::query()->forVendorPayments()->orderBy('sort_order')->get(['id', 'name', 'requires_reference']),
            'canPay' => $user->can('payments.pay'),
        ]);
    }

    private function table(Request $request): TableQuery
    {
        return TableQuery::fromRequest($request, sortable: ['name', 'balance', 'company'], filterable: ['status', 'hub', 'ordered'], defaultSort: 'name');
    }

    /** @return EloquentBuilder<Vendor> */
    private function filtered(TableQuery $table, string $tz): EloquentBuilder
    {
        $query = Vendor::query();
        if ($table->search !== '') {
            $query->search($table->search);
        }
        match ($table->filters['status'] ?? null) {
            'active' => $query->where('is_active', true),
            'inactive' => $query->where('is_active', false),
            'owing' => $query->where('balance', '>', 0),
            'cleared' => $query->where('balance', '<=', 0),
            default => null,
        };
        if (isset($table->filters['hub'])) {
            $query->where('address', 'like', '%'.addcslashes($table->filters['hub'], '\\%_').'%');
        }
        // "Date:" = last received purchase from the vendor, across the tenant's branches.
        $recency = RecencyFilter::resolve($table->filters['ordered'] ?? null, $tz);
        if ($recency !== null) {
            [$cut, $active] = $recency;
            $ordered = static fn (Builder $q): Builder => $q->selectRaw('1')->from('purchases')
                ->whereColumn('purchases.vendor_id', 'vendors.id')
                ->whereColumn('purchases.tenant_id', 'vendors.tenant_id')
                ->where('purchases.status', PurchaseStatus::Received->value)
                ->where('purchases.purchased_at', '>=', $cut);
            if ($active) {
                $query->whereExists($ordered);
            } else {
                $query->whereNotExists($ordered);
            }
        }

        return $query;
    }

    /** GET /app/vendors/export.{csv|xlsx|pdf}: the filtered list (same query string as the page). */
    public function export(Request $request, string $format, TabularExport $export, TenantContext $context): HttpResponse|StreamedResponse
    {
        $this->authorize('viewAny', Vendor::class);
        $tz = BusinessDay::timezone($context->tenant());
        $query = $this->filtered($this->table($request), $tz);

        // Open bills across the tenant's branches (a vendor is tenant-wide, like its balance).
        /** @var array<int, int> $open */
        $open = Purchase::query()->withoutBranchScope()->where('status', PurchaseStatus::Received->value)->where('due', '>', 0)
            ->whereIn('vendor_id', (clone $query)->toBase()->reorder()->select('id'))
            ->groupBy('vendor_id')->toBase()->selectRaw('vendor_id, count(*) as open_bills')->pluck('open_bills', 'vendor_id')
            ->map(static fn (mixed $n): int => (int) $n)->all();

        $columns = [
            new ReportColumn('name', 'Name', ColumnType::Text),
            new ReportColumn('company', 'Company', ColumnType::Text),
            new ReportColumn('phone', 'Phone', ColumnType::Text),
            new ReportColumn('hub', 'Hub', ColumnType::Text),
            new ReportColumn('open_bills', 'Open bills', ColumnType::Int),
            new ReportColumn('balance', 'Balance', ColumnType::Money),
        ];
        $rows = [];
        $balance = 0;
        $bills = 0;
        foreach ($query->orderBy('name')->orderBy('id')->lazy(500) as $v) {
            /** @var Vendor $v */
            $n = $open[$v->id] ?? 0;
            $balance += $v->balance->paisa;
            $bills += $n;
            $rows[] = ['name' => $v->name, 'company' => (string) $v->company, 'phone' => (string) $v->phone, 'hub' => (string) $v->address, 'open_bills' => $n, 'balance' => $v->balance->paisa];
        }

        return $export->respond(
            new ReportResult($columns, $rows, ['open_bills' => $bills, 'balance' => $balance]),
            ExportFormat::from($format),
            'vendors-'.now($tz)->format('Ymd-His'),
            ExportMeta::for($context, 'Vendors'),
        );
    }

    public function create(): Response
    {
        $this->authorize('create', Vendor::class);

        return Inertia::render('vendors/form', ['vendor' => null]);
    }

    public function store(SaveVendorRequest $request, SaveVendor $save): RedirectResponse
    {
        $vendor = $this->guarded(fn (): Vendor => $save->handle($this->actor($request), $this->data($request)));
        Inertia::flash('toast', ['type' => 'success', 'message' => __('Vendor saved.')]);

        return to_route('vendors.show', $vendor);
    }

    /** The mockup's Vendor 360 profile: KPIs, registration details, bills, disbursements and the statement ledger. */
    public function show(Request $request, Vendor $vendor, TenantContext $context): Response
    {
        $this->authorize('view', $vendor);
        $user = $this->actor($request);
        $tz = BusinessDay::timezone($context->tenant());
        $allBranches = $user->can('reports.all_branches');

        $purchaseQuery = Purchase::query()->where('vendor_id', $vendor->id)->with('branch:id,code,name');
        $paymentQuery = Payment::query()->where('party_type', PartyType::Vendor)->where('party_id', $vendor->id)
            ->where('direction', PaymentDirection::Out)->whereNull('voided_at')
            ->with(['paymentMethod:id,name', 'allocations']);
        if ($allBranches) {
            $purchaseQuery->withoutBranchScope();
            $paymentQuery->withoutBranchScope();
        }

        $purchases = $purchaseQuery->latest('purchased_at')->latest('id')->limit(200)->get();
        $payments = $paymentQuery->latest('paid_at')->latest('id')->limit(200)->get();

        $purchaseNumbers = Purchase::query()->withoutBranchScope()
            ->whereIn('id', $payments->flatMap(static fn (Payment $p) => $p->allocations->where('allocatable_type', AllocatableType::Purchase)->pluck('allocatable_id'))->unique()->all())
            ->pluck('number', 'id');

        $received = $purchases->where('status', PurchaseStatus::Received);
        $yearStart = Carbon::now($tz)->startOfYear();
        $ytd = $received->filter(static fn (Purchase $p): bool => $p->purchased_at->greaterThanOrEqualTo($yearStart));

        return Inertia::render('vendors/show', [
            'vendor' => $this->row($vendor) + ['email' => $vendor->email, 'address' => $vendor->address, 'notes' => $vendor->notes],
            'kpis' => [
                'purchases_ytd' => $ytd->sum(static fn (Purchase $p): int => $p->total->paisa),
                'shipments_ytd' => $ytd->count(),
                'paid_total' => $payments->sum(static fn (Payment $p): int => $p->amount->paisa),
                'open_bills' => $received->filter(static fn (Purchase $p): bool => $p->due->paisa > 0)->count(),
                'last_purchase_at' => BusinessDay::localDate($purchases->first()?->purchased_at, $tz),
                'all_branches' => $allBranches,
            ],
            'purchases' => $purchases->map(static fn (Purchase $p): array => [
                'id' => $p->id,
                'uuid' => $p->uuid,
                'number' => $p->number,
                'status' => $p->status->value,
                'payment_status' => $p->payment_status->value,
                'purchased_at' => BusinessDay::localDate($p->purchased_at, $tz),
                'branch' => $p->branch?->name,
                'branch_code' => $p->branch?->code,
                'total' => $p->total->paisa,
                'paid' => $p->paid->paisa,
                'due' => $p->due->paisa,
            ])->all(),
            'payments' => $payments->map(static fn (Payment $p): array => [
                'id' => $p->id,
                'number' => $p->number,
                'paid_at' => BusinessDay::localDate($p->paid_at, $tz),
                'method' => $p->paymentMethod?->name,
                'amount' => $p->amount->paisa,
                'reference' => $p->reference,
                'bills' => $p->allocations->where('allocatable_type', AllocatableType::Purchase)
                    ->map(static fn ($a): string => (string) ($purchaseNumbers[$a->allocatable_id] ?? ''))->filter()->values()->all(),
            ])->all(),
            'paymentMethods' => PaymentMethod::query()->forVendorPayments()->orderBy('sort_order')->get(['id', 'name', 'requires_reference']),
            'canPay' => $user->can('payments.pay'),
        ]);
    }

    public function edit(Vendor $vendor): Response
    {
        $this->authorize('update', $vendor);

        return Inertia::render('vendors/form', [
            'vendor' => ['id' => $vendor->id, 'uuid' => $vendor->uuid, 'name' => $vendor->name, 'company' => $vendor->company, 'phone' => $vendor->phone, 'email' => $vendor->email, 'address' => $vendor->address, 'notes' => $vendor->notes],
        ]);
    }

    public function update(SaveVendorRequest $request, Vendor $vendor, SaveVendor $save): RedirectResponse
    {
        $this->guarded(fn (): Vendor => $save->handle($this->actor($request), $this->data($request), $vendor));
        Inertia::flash('toast', ['type' => 'success', 'message' => __('Vendor saved.')]);

        return to_route('vendors.show', $vendor);
    }

    public function destroy(Request $request, Vendor $vendor, DeactivateVendor $deactivate): RedirectResponse
    {
        $this->authorize('delete', $vendor);
        $this->guarded(fn (): Vendor => $deactivate->handle($this->actor($request), $vendor));
        Inertia::flash('toast', ['type' => 'success', 'message' => __('Vendor deactivated.')]);

        return to_route('vendors.index');
    }

    public function pay(PayVendorRequest $request, Vendor $vendor, PayVendor $pay): RedirectResponse
    {
        $this->authorize('view', $vendor);
        $this->guarded(fn (): Payment => $pay->handle($this->actor($request), [
            'vendor_id' => $vendor->id,
            'amount' => Money::ofPaisa((int) $request->validated('amount')),
            'payment_method_id' => (int) $request->validated('payment_method_id'),
            'reference' => $request->validated('reference'),
            'note' => $request->validated('note'),
        ]), 'amount');
        Inertia::flash('toast', ['type' => 'success', 'message' => __('Payment recorded.')]);

        return back();
    }

    /** The vendor's hub: the last comma-separated part of its address (vendors have no separate hub field). */
    private function hubOf(?string $address): ?string
    {
        if ($address === null) {
            return null;
        }
        $parts = array_values(array_filter(array_map(trim(...), explode(',', $address)), static fn (string $p): bool => $p !== ''));
        $hub = $parts === [] ? '' : $parts[count($parts) - 1];

        return $hub === '' ? null : $hub;
    }

    /**
     * Hub chips: the most common hubs among the tenant's vendors.
     *
     * @return list<string>
     */
    private function hubs(): array
    {
        $counts = [];
        foreach (Vendor::query()->whereNotNull('address')->pluck('address') as $address) {
            $hub = $this->hubOf((string) $address);
            if ($hub !== null) {
                $counts[$hub] = ($counts[$hub] ?? 0) + 1;
            }
        }
        arsort($counts);

        return array_slice(array_map(strval(...), array_keys($counts)), 0, 6);
    }

    /** @return array<string, mixed> */
    private function row(Vendor $vendor): array
    {
        return [
            'id' => $vendor->id,
            'uuid' => $vendor->uuid,
            'name' => $vendor->name,
            'company' => $vendor->company,
            'phone' => $vendor->phone,
            'address' => $vendor->address,
            'hub' => $this->hubOf($vendor->address),
            'balance' => $vendor->balance->paisa,
            'is_active' => $vendor->is_active,
        ];
    }

    /** @return array{name: string, company: string|null, phone: string|null, email: string|null, address: string|null, notes: string|null} */
    private function data(SaveVendorRequest $request): array
    {
        return [
            'name' => (string) $request->validated('name'),
            'company' => $request->validated('company'),
            'phone' => $request->validated('phone'),
            'email' => $request->validated('email'),
            'address' => $request->validated('address'),
            'notes' => $request->validated('notes'),
        ];
    }

    private function actor(Request $request): User
    {
        $user = $request->user();
        abort_unless($user instanceof User, 401);

        return $user;
    }
}
