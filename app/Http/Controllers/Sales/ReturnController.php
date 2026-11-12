<?php

declare(strict_types=1);

namespace App\Http\Controllers\Sales;

use App\Actions\Sales\CreateSaleReturn;
use App\Actions\Sales\Data\ReturnDraft;
use App\Enums\AllocatableType;
use App\Enums\RefundMode;
use App\Enums\SaleStatus;
use App\Http\Controllers\Concerns\TranslatesDomainErrors;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Sales\Concerns\PresentsSales;
use App\Http\Requests\Sales\StoreSaleReturnRequest;
use App\Models\Customer;
use App\Models\Payment;
use App\Models\PaymentAllocation;
use App\Models\PaymentMethod;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\SaleReturn;
use App\Models\SaleReturnItem;
use App\Models\User;
use App\Reports\ColumnType;
use App\Reports\ReportColumn;
use App\Reports\ReportResult;
use App\Sms\SmsNotice;
use App\Sms\Templates;
use App\Support\Export\ExportFormat;
use App\Support\Export\ExportMeta;
use App\Support\Export\TabularExport;
use App\Support\Http\TableQuery;
use App\Support\Sales\BusinessDay;
use App\Support\Sales\OrderDiscountShares;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response as HttpResponse;
use Illuminate\Support\Carbon;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** Returns ledger (current branch), the return / exchange wizard and the return detail. */
final class ReturnController extends Controller
{
    use AuthorizesRequests, PresentsSales, TranslatesDomainErrors;

    public function index(Request $request): Response
    {
        $this->authorize('viewAny', SaleReturn::class);

        $table = $this->listTable($request);
        $query = $this->listQuery($table);

        $sums = (clone $query)->toBase()
            ->selectRaw('COALESCE(SUM(total), 0) AS total, COALESCE(SUM(refunded), 0) AS refunded, COALESCE(SUM(credited), 0) AS credited')
            ->first();

        $query->with(['sale:id,number', 'customer' => fn ($q) => $q->withTrashed(), 'exchangeSale:id,number,total', 'items.saleItem:id,product_name'])
            ->withCount(['items', 'items as restocked_count' => fn ($q) => $q->where('restock', true)]);
        $page = $table->paginate($query);

        /** @var list<SaleReturn> $returns */
        $returns = $page->items();
        $methods = $this->refundMethods(array_map(static fn (SaleReturn $r): int => $r->id, $returns));
        $staff = User::inCurrentTenant()->whereIn('id', array_filter(array_map(static fn (SaleReturn $r): ?int => $r->created_by, $returns)))->pluck('name', 'id');

        return Inertia::render('returns/index', [
            'rows' => array_map(fn (SaleReturn $r): array => [
                'id' => $r->id,
                'number' => $r->number,
                'returned_at' => $this->localTime($r->returned_at),
                'sale' => ['id' => $r->sale_id, 'number' => (string) $r->sale?->number],
                'customer' => $this->customerSummary($r->customer),
                'items' => (int) $r->getAttribute('items_count'),
                'restocked' => (int) $r->getAttribute('restocked_count'),
                'lines' => array_values($r->items->sortBy('id')->map(static fn (SaleReturnItem $i): array => [
                    'name' => $i->saleItem->product_name ?? '',
                    'qty' => $i->qty->milli,
                ])->all()),
                'total' => $r->total->paisa,
                'refunded' => $r->refunded->paisa,
                'credited' => $r->credited->paisa,
                'refund_mode' => $r->refund_mode->value,
                'refund_method' => $methods[$r->id] ?? null,
                'reason' => $r->reason,
                'cashier' => $r->created_by !== null ? $staff->get($r->created_by) : null,
                'exchange_sale' => $r->exchangeSale instanceof Sale ? [
                    'id' => $r->exchangeSale->id,
                    'number' => (string) $r->exchangeSale->number,
                    'difference' => $r->exchangeSale->total->minus($r->total)->paisa,
                ] : null,
            ], $returns),
            'meta' => ['page' => $page->currentPage(), 'perPage' => $page->perPage(), 'total' => $page->total(), 'lastPage' => $page->lastPage()],
            'totals' => [
                'total' => (int) ($sums->total ?? 0),
                'refunded' => (int) ($sums->refunded ?? 0),
                'credited' => (int) ($sums->credited ?? 0),
            ],
            'filters' => [...$table->filters, 'q' => $table->search],
            'reasons' => StoreSaleReturnRequest::REASONS,
            'cashiers' => fn (): array => array_values(User::inCurrentTenant()->orderBy('name')->get(['id', 'name'])
                ->map(static fn (User $u): array => ['id' => $u->id, 'name' => $u->name])->all()),
            // The return wizard dialog (reloaded alone with wizard_q / wizard_sale).
            'wizard' => Inertia::optional(fn (): array => $this->wizardPayload($request)),
        ]);
    }

    private function listTable(Request $request): TableQuery
    {
        return TableQuery::fromRequest($request, ['number', 'returned_at', 'total'], ['from', 'to', 'period', 'mode', 'reason', 'cashier'], '-returned_at');
    }

    /**
     * The filtered returns list (the same query string as the page), shared by the page and the export.
     *
     * @return Builder<SaleReturn>
     */
    private function listQuery(TableQuery $table): Builder
    {
        $query = SaleReturn::query();
        $this->dateRange($query, 'returned_at', $table->filters['from'] ?? null, $table->filters['to'] ?? null, $table->filters['period'] ?? null);
        $mode = RefundMode::tryFrom($table->filters['mode'] ?? '');
        if ($mode instanceof RefundMode) {
            $query->where('refund_mode', $mode);
        }
        $reason = $table->filters['reason'] ?? null;
        if (in_array($reason, StoreSaleReturnRequest::REASONS, true)) {
            $query->where('reason', $reason);
        }
        if (isset($table->filters['cashier'])) {
            $query->where('created_by', (int) $table->filters['cashier']);
        }
        if ($table->search !== '') {
            $prefix = addcslashes($table->search, '\\%_').'%';
            $query->where(fn ($w) => $w->where('number', 'like', $prefix)
                ->orWhereIn('sale_id', Sale::query()->select('id')->where('number', 'like', $prefix))
                ->orWhereIn('customer_id', Customer::query()->withTrashed()->select('id')->search($table->search)));
        }

        return $query;
    }

    /** GET /app/returns/export.{csv|xlsx|pdf}: the whole filtered list, same query string as the page. */
    public function export(Request $request, string $format, TabularExport $export, TenantContext $context): HttpResponse|StreamedResponse
    {
        $this->authorize('viewAny', SaleReturn::class);
        $table = $this->listTable($request);
        $query = $table->applySort($this->listQuery($table)
            ->with(['sale:id,number', 'customer' => fn ($q) => $q->withTrashed()])
            ->withCount(['items', 'items as restocked_count' => fn ($q) => $q->where('restock', true)]));
        $staff = User::inCurrentTenant()->pluck('name', 'id');
        $tz = $this->timezone();

        $columns = [
            new ReportColumn('number', 'Return', ColumnType::Text),
            new ReportColumn('sale', 'Invoice', ColumnType::Text),
            new ReportColumn('date', 'Date', ColumnType::Date),
            new ReportColumn('customer', 'Customer', ColumnType::Text),
            new ReportColumn('items', 'Items', ColumnType::Int),
            new ReportColumn('restocked', 'Restocked', ColumnType::Int),
            new ReportColumn('total', 'Total', ColumnType::Money),
            new ReportColumn('refunded', 'Refunded', ColumnType::Money),
            new ReportColumn('credited', 'Credited', ColumnType::Money),
            new ReportColumn('refund_mode', 'Settlement', ColumnType::Text),
            new ReportColumn('reason', 'Reason', ColumnType::Text),
            new ReportColumn('cashier', 'Cashier', ColumnType::Text),
        ];
        $rows = [];
        $totals = ['items' => 0, 'restocked' => 0, 'total' => 0, 'refunded' => 0, 'credited' => 0];
        foreach ($query->lazy(500) as $r) {
            /** @var SaleReturn $r */
            $customer = $this->customerSummary($r->customer);
            $row = [
                'number' => $r->number,
                'sale' => (string) $r->sale?->number,
                'date' => BusinessDay::localDate($r->returned_at, $tz),
                'customer' => $customer === null || $customer['walk_in'] ? 'Walk-in' : (string) $customer['name'],
                'items' => (int) $r->getAttribute('items_count'),
                'restocked' => (int) $r->getAttribute('restocked_count'),
                'total' => $r->total->paisa,
                'refunded' => $r->refunded->paisa,
                'credited' => $r->credited->paisa,
                'refund_mode' => $r->refund_mode->value,
                'reason' => $r->reason,
                'cashier' => $r->created_by !== null ? $staff->get($r->created_by) : null,
            ];
            $rows[] = $row;
            foreach (array_keys($totals) as $key) {
                $totals[$key] += (int) $row[$key];
            }
        }

        return $export->respond(new ReportResult($columns, $rows, $totals), ExportFormat::from($format), 'returns-'.now($tz)->format('Ymd-His'), ExportMeta::for($context, 'Returns'));
    }

    public function create(Request $request): Response
    {
        $this->authorize('create', SaleReturn::class);

        return $this->wizard($request, 'return');
    }

    public function createExchange(Request $request): Response
    {
        $this->authorize('create', SaleReturn::class);
        $this->authorize('create', Sale::class);

        return $this->wizard($request, 'exchange');
    }

    public function store(StoreSaleReturnRequest $request, CreateSaleReturn $create): RedirectResponse
    {
        $this->authorize('create', SaleReturn::class);

        $return = $this->guarded(fn (): SaleReturn => $create->handle($this->actor($request), new ReturnDraft(
            saleId: $request->integer('sale_id'),
            lines: $this->returnLines($request),
            refundMode: RefundMode::from($request->string('refund_mode')->value()),
            reason: $request->string('reason')->value(),
            refundMethodId: $request->filled('refund_method_id') ? $request->integer('refund_method_id') : null,
            note: $request->string('note')->trim()->value() ?: null,
        )), 'lines');

        Inertia::flash('toast', ['type' => 'success', 'message' => __('receipts.flash.returned', ['number' => $return->number])]);
        SmsNotice::flash(Templates::SALE_RETURNED, Customer::query()->find($return->customer_id));

        return to_route('returns.show', $return->id);
    }

    public function show(Request $request, int $return): Response
    {
        $model = SaleReturn::query()->with(['items.saleItem', 'sale', 'exchangeSale', 'customer' => fn ($q) => $q->withTrashed()])->findOrFail($return);
        $this->authorize('view', $model);
        $canCost = $this->actor($request)->can('products.view_cost');
        $creator = $model->created_by !== null ? User::inCurrentTenant()->find($model->created_by) : null;

        return Inertia::render('returns/show', [
            'saleReturn' => [
                'id' => $model->id,
                'number' => $model->number,
                'returned_at' => $this->localTime($model->returned_at),
                'created_by' => $creator?->name,
                'refund_mode' => $model->refund_mode->value,
                'reason' => $model->reason,
                'note' => $model->note,
                'subtotal' => $model->subtotal->paisa,
                'tax_total' => $model->tax_total->paisa,
                'total' => $model->total->paisa,
                'refunded' => $model->refunded->paisa,
                'credited' => $model->credited->paisa,
            ],
            'sale' => ['id' => $model->sale_id, 'number' => (string) $model->sale?->number, 'sold_at' => $this->localTime($model->sale?->sold_at)],
            'exchange_sale' => $model->exchangeSale instanceof Sale ? [
                'id' => $model->exchangeSale->id,
                'number' => (string) $model->exchangeSale->number,
                'total' => $model->exchangeSale->total->paisa,
                'difference' => $model->exchangeSale->total->minus($model->total)->paisa,
            ] : null,
            'customer' => $this->customerSummary($model->customer),
            'lines' => array_values($model->items->sortBy('id')->map(static fn (SaleReturnItem $i): array => [
                'id' => $i->id,
                'name' => $i->saleItem->product_name ?? '',
                'sku' => $i->saleItem->sku ?? '',
                'qty' => $i->qty->milli,
                'unit_price' => $i->unit_price->paisa,
                'tax_amount' => $i->tax_amount->paisa,
                'line_total' => $i->line_total->paisa,
                'restock' => $i->restock,
                'cogs_reversed' => $canCost ? $i->cogs_reversed->paisa : null,
            ])->all()),
        ]);
    }

    /** The shared return / exchange wizard page (returns.create and exchanges.create). */
    private function wizard(Request $request, string $mode): Response
    {
        $q = trim($request->string('q')->value());
        $saleId = $request->integer('sale');

        return Inertia::render('returns/create', [
            'mode' => $mode,
            'q' => $q,
            ...$this->wizardData($request, $q, $saleId),
        ]);
    }

    /** @return array<string, mixed> The return wizard dialog on the ledger. */
    private function wizardPayload(Request $request): array
    {
        $this->authorize('create', SaleReturn::class);
        $q = trim($request->string('wizard_q')->value());

        return ['q' => $q, ...$this->wizardData($request, $q, $request->integer('wizard_sale'))];
    }

    /** @return array{candidates: list<array<string, mixed>>, sale: array<string, mixed>|null, methods: list<array<string, mixed>>, reasons: list<string>, can: array{exchange: bool}} */
    private function wizardData(Request $request, string $q, int $saleId): array
    {
        $actor = $this->actor($request);
        $sale = $saleId > 0 ? Sale::query()->with(['items', 'customer' => fn ($c) => $c->withTrashed()])->find($saleId) : null;
        if ($sale instanceof Sale) {
            $this->authorize('view', $sale);
        }

        return [
            'candidates' => $sale === null && mb_strlen($q) >= 2 ? $this->candidates($q) : [],
            'sale' => $sale instanceof Sale ? $this->returnableSale($sale) : null,
            'methods' => $this->moneyMethods(),
            'reasons' => StoreSaleReturnRequest::REASONS,
            'can' => [
                'exchange' => $actor->can('create', SaleReturn::class) && $actor->can('create', Sale::class),
            ],
        ];
    }

    /**
     * Refund method name per return (the Out payment allocated to it).
     *
     * @param  list<int>  $returnIds
     * @return array<int, string>
     */
    private function refundMethods(array $returnIds): array
    {
        if ($returnIds === []) {
            return [];
        }
        $allocations = PaymentAllocation::query()
            ->where('allocatable_type', AllocatableType::SaleReturn)
            ->whereIn('allocatable_id', $returnIds)
            ->orderBy('id')
            ->get();
        $payments = Payment::query()->withoutBranchScope()->with('paymentMethod')
            ->whereIn('id', $allocations->pluck('payment_id')->all())->get()->keyBy('id');

        $out = [];
        foreach ($allocations as $allocation) {
            $method = $payments->get($allocation->payment_id)?->paymentMethod;
            if ($method instanceof PaymentMethod && ! isset($out[$allocation->allocatable_id])) {
                $out[$allocation->allocatable_id] = $method->name;
            }
        }

        return $out;
    }

    /** @return list<array<string, mixed>> */
    private function candidates(string $q): array
    {
        $prefix = addcslashes($q, '\\%_').'%';

        return array_values(Sale::query()
            ->where('status', SaleStatus::Completed)
            ->whereNotNull('number')
            ->where(fn ($w) => $w->where('number', 'like', $prefix)
                ->orWhereIn('customer_id', Customer::query()->withTrashed()->select('id')->search($q)))
            ->with(['customer' => fn ($c) => $c->withTrashed()])
            ->withCount('items')
            ->latest('sold_at')
            ->limit(15)
            ->get()
            ->map(fn (Sale $s): array => [
                'id' => $s->id,
                'number' => (string) $s->number,
                'sold_at' => $this->localTime($s->sold_at),
                'customer' => $this->customerSummary($s->customer),
                'items' => (int) $s->getAttribute('items_count'),
                'total' => $s->total->paisa,
                'due' => $s->due->paisa,
                'returned' => $s->returned_total->paisa,
            ])->all());
    }

    /** @return array<string, mixed> */
    private function returnableSale(Sale $sale): array
    {
        /** @var list<SaleItem> $items */
        $items = $sale->items->sortBy('id')->values()->all();
        $shares = OrderDiscountShares::of($sale, $items);
        $tenant = app(TenantContext::class)->tenant();
        $window = $tenant->setting('return_days');
        $age = BusinessDay::daysBetween($sale->sold_at, Carbon::now(), BusinessDay::timezone($tenant));

        return [
            'id' => $sale->id,
            'number' => (string) $sale->number,
            'status' => $sale->status->value,
            'sold_at' => $this->localTime($sale->sold_at),
            'customer' => $this->customerSummary($sale->customer),
            'total' => $sale->total->paisa,
            'paid' => $sale->paid->paisa,
            'due' => $sale->due->paisa,
            'returned_total' => $sale->returned_total->paisa,
            'window' => ['days' => is_int($window) ? $window : null, 'age' => $age, 'expired' => is_int($window) && $age > $window],
            'lines' => array_map(static fn (SaleItem $i): array => [
                'id' => $i->id,
                'product_id' => $i->product_id,
                'name' => $i->product_name,
                'sku' => $i->sku,
                'qty' => $i->qty->milli,
                'qty_returned' => $i->qty_returned->milli,
                'returnable' => $i->qty->minus($i->qty_returned)->milli,
                'unit_price' => $i->unit_price->paisa,
                'payable' => $i->line_total->minus($shares[$i->id])->paisa,
            ], $items),
        ];
    }
}
