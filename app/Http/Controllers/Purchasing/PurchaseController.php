<?php

declare(strict_types=1);

namespace App\Http\Controllers\Purchasing;

use App\Actions\Purchasing\CancelPurchase;
use App\Actions\Purchasing\PayVendor;
use App\Actions\Purchasing\ReceivePurchase;
use App\Enums\AllocatableType;
use App\Enums\ProductItemType;
use App\Enums\PurchaseStatus;
use App\Enums\StockSourceType;
use App\Exceptions\Domain\DomainException;
use App\Exceptions\Domain\DuplicateVendorBill;
use App\Http\Controllers\Concerns\TranslatesDomainErrors;
use App\Http\Controllers\Controller;
use App\Http\Requests\Purchasing\ReceivePurchaseRequest;
use App\Http\Requests\Vendors\PayVendorRequest;
use App\Models\Branch;
use App\Models\Payment;
use App\Models\PaymentAllocation;
use App\Models\PaymentMethod;
use App\Models\Product;
use App\Models\Purchase;
use App\Models\PurchaseItem;
use App\Models\StockBatch;
use App\Models\Unit;
use App\Models\User;
use App\Models\Vendor;
use App\Reports\ColumnType;
use App\Reports\ReportColumn;
use App\Reports\ReportResult;
use App\Support\Catalog\ProductLookup;
use App\Support\Dates\Period;
use App\Support\Export\ExportFormat;
use App\Support\Export\ExportMeta;
use App\Support\Export\TabularExport;
use App\Support\Http\TableQuery;
use App\Support\Money\Money;
use App\Support\Quantity\Quantity;
use App\Support\Sales\BusinessDay;
use App\Support\Stock\BranchStockColumns;
use App\Support\Tenancy\TenantContext;
use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response as HttpResponse;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class PurchaseController extends Controller
{
    use AuthorizesRequests;
    use TranslatesDomainErrors;

    public function index(Request $request): Response
    {
        $this->authorize('viewAny', Purchase::class);
        $table = TableQuery::fromRequest($request, sortable: ['number', 'purchased_at', 'total', 'paid', 'due', 'vendor_ref'], filterable: ['status', 'payment', 'vendor', 'branch', 'from', 'to', 'period'], defaultSort: '-purchased_at');
        [$filtered, $branchList] = $this->filtered($request, $table);
        $tz = $this->timezone();
        $query = $filtered()->with(['vendor:id,name', 'branch:id,name,code'])->withSum('items as qty_sum', 'qty')->withCount('items');

        // KPI strip and totals row: the whole filtered set, not only this page. Cancelled bills carry no stock or payable.
        $sums = $filtered()->where('status', PurchaseStatus::Received->value)->toBase()
            ->selectRaw('count(*) as bills, coalesce(sum(total), 0) as total, coalesce(sum(paid), 0) as paid, coalesce(sum(due), 0) as due')->first();
        $qtyTotal = $filtered()->where('purchases.status', PurchaseStatus::Received->value)->toBase()
            ->join('purchase_items', 'purchase_items.purchase_id', '=', 'purchases.id')->sum('purchase_items.qty');
        $vendorsOwing = Vendor::query()->where('balance', '>', 0)->count();

        $page = $table->paginate($query);

        return Inertia::render('purchases/index', [
            'rows' => collect($page->items())->map(static fn (Purchase $p): array => [
                'id' => $p->id,
                'uuid' => $p->uuid,
                'number' => $p->number,
                'vendor' => $p->vendor->name ?? null,
                'vendor_ref' => $p->vendor_ref,
                'status' => $p->status->value,
                'payment_status' => $p->payment_status->value,
                'purchased_at' => BusinessDay::localDate($p->purchased_at, $tz),
                'branch' => $p->branch instanceof Branch ? ['name' => $p->branch->name, 'code' => (string) $p->branch->code] : null,
                'current_branch' => $p->branch_id === app(TenantContext::class)->branchId(),
                'items' => (int) $p->getAttribute('items_count'),
                'qty' => Quantity::parse((string) ($p->getAttribute('qty_sum') ?? '0'))->milli,
                'total' => $p->total->paisa,
                'paid' => $p->paid->paisa,
                'due' => $p->due->paisa,
            ])->all(),
            'meta' => ['page' => $page->currentPage(), 'perPage' => $page->perPage(), 'total' => $page->total(), 'lastPage' => $page->lastPage()],
            'totals' => [
                'bills' => (int) ($sums->bills ?? 0),
                'total' => (int) ($sums->total ?? 0),
                'paid' => (int) ($sums->paid ?? 0),
                'due' => (int) ($sums->due ?? 0),
                'qty' => Quantity::parse((string) ($qtyTotal ?: '0'))->milli,
                'vendors_owing' => $vendorsOwing,
            ],
            'branches' => $branchList,
            'vendors' => Vendor::query()->orderBy('name')->get(['id', 'name']),
        ]);
    }

    /**
     * The filtered purchases query (same query string as the page) as a factory, plus the branch chips; shared by the page and the export.
     *
     * @return array{0: Closure(): Builder<Purchase>, 1: list<array<string, mixed>>}
     */
    private function filtered(Request $request, TableQuery $table): array
    {
        $branches = app(BranchStockColumns::class);
        $user = $this->actor($request);
        $currentBranchId = app(TenantContext::class)->branchId();

        // Branch chips exist only for users with reports.all_branches (default "All Branches", as the mockup);
        // everyone else stays on the current branch.
        $branchList = $user->can('reports.all_branches') ? $branches->present($branches->branchesFor($user)) : [];
        $branchFilter = $branchList === [] ? '' : ($table->filters['branch'] ?? 'all');
        $tz = $this->timezone();
        $filtered = static function () use ($table, $branchFilter, $branchList, $currentBranchId, $tz): Builder {
            $query = Purchase::query();
            if ($branchFilter === 'all') {
                $query->withoutBranchScope();
            } elseif ($branchFilter !== '' && (int) $branchFilter !== $currentBranchId) {
                abort_unless(collect($branchList)->contains('id', (int) $branchFilter), 404);
                $query->withoutBranchScope()->where('branch_id', (int) $branchFilter);
            }
            if ($table->search !== '') {
                $like = '%'.addcslashes($table->search, '\\%_').'%';
                $query->where(static fn ($q) => $q->where('number', 'like', $like)->orWhere('vendor_ref', 'like', $like)
                    ->orWhereHas('vendor', static fn ($v) => $v->search($table->search)));
            }
            if (isset($table->filters['status'])) {
                $query->where('status', $table->filters['status']);
            }
            if (isset($table->filters['payment'])) {
                // `due` is the mockup's "Outstanding balance (> 0)": unpaid and partly paid bills.
                $table->filters['payment'] === 'due'
                    ? $query->where('due', '>', 0)->where('status', PurchaseStatus::Received->value)
                    : $query->where('payment_status', $table->filters['payment']);
            }
            // filter[from] / filter[to] are tenant-local days; purchased_at is stored in UTC.
            $named = Period::range($table->filters['period'] ?? null, $tz);
            BusinessDay::whereLocalDates($query, 'purchased_at', $named['from'] ?? $table->filters['from'] ?? null, $named['to'] ?? $table->filters['to'] ?? null, $tz);
            if (isset($table->filters['vendor'])) {
                $query->where('vendor_id', (int) $table->filters['vendor']);
            }

            return $query;
        };

        return [$filtered, $branchList];
    }

    /** GET /app/purchases/export.{csv|xlsx|pdf}: the whole filtered list, same query string as the page. */
    public function export(Request $request, string $format, TabularExport $export, TenantContext $context): HttpResponse|StreamedResponse
    {
        $this->authorize('viewAny', Purchase::class);
        $table = TableQuery::fromRequest($request, sortable: ['number', 'purchased_at', 'total', 'paid', 'due', 'vendor_ref'], filterable: ['status', 'payment', 'vendor', 'branch', 'from', 'to', 'period'], defaultSort: '-purchased_at');
        [$filtered] = $this->filtered($request, $table);
        $tz = $this->timezone();
        $query = $table->applySort($filtered()->with(['vendor:id,name', 'branch:id,name,code'])->withSum('items as qty_sum', 'qty')->withCount('items'));

        $columns = [
            new ReportColumn('number', 'GRN', ColumnType::Text),
            new ReportColumn('date', 'Date', ColumnType::Date),
            new ReportColumn('vendor', 'Vendor', ColumnType::Text),
            new ReportColumn('branch', 'Branch', ColumnType::Text),
            new ReportColumn('vendor_ref', 'Bill ref', ColumnType::Text),
            new ReportColumn('items', 'Items', ColumnType::Int),
            new ReportColumn('qty', 'Qty', ColumnType::Qty),
            new ReportColumn('total', 'Total bill', ColumnType::Money),
            new ReportColumn('paid', 'Paid', ColumnType::Money),
            new ReportColumn('due', 'Due', ColumnType::Money),
            new ReportColumn('status', 'Status', ColumnType::Text),
            new ReportColumn('payment_status', 'Payment', ColumnType::Text),
        ];
        $rows = [];
        $totals = ['items' => 0, 'qty' => 0, 'total' => 0, 'paid' => 0, 'due' => 0];
        foreach ($query->lazy(500) as $p) {
            /** @var Purchase $p */
            $row = [
                'number' => $p->number,
                'date' => BusinessDay::localDate($p->purchased_at, $tz),
                'vendor' => $p->vendor->name ?? __('purchases.no_vendor'),
                'branch' => $p->branch instanceof Branch ? $p->branch->name : null,
                'vendor_ref' => $p->vendor_ref,
                'items' => (int) $p->getAttribute('items_count'),
                'qty' => Quantity::parse((string) ($p->getAttribute('qty_sum') ?? '0'))->milli,
                'total' => $p->total->paisa,
                'paid' => $p->paid->paisa,
                'due' => $p->due->paisa,
                'status' => $p->status->value,
                'payment_status' => $p->payment_status->value,
            ];
            $rows[] = $row;
            if ($p->status === PurchaseStatus::Received) {
                foreach (array_keys($totals) as $key) {
                    $totals[$key] += (int) $row[$key];
                }
            }
        }

        return $export->respond(new ReportResult($columns, $rows, $totals), ExportFormat::from($format), 'purchases-'.now($tz)->format('Ymd-His'), ExportMeta::for($context, 'Purchases'));
    }

    public function create(Request $request): Response
    {
        $this->authorize('create', Purchase::class);

        return Inertia::render('purchases/create', [
            'vendors' => Vendor::query()->where('is_active', true)->orderBy('name')->get(['id', 'name']),
            'paymentMethods' => PaymentMethod::query()->forVendorPayments()->orderBy('sort_order')->get(['id', 'name', 'requires_reference']),
            'canPay' => $this->actor($request)->can('payments.pay'),
            'canCreateItem' => $this->actor($request)->can('create', Product::class),
            'units' => Unit::query()->orderBy('name')->get(['id', 'name', 'short_name', 'allows_decimal']),
            'today' => BusinessDay::today($this->timezone()),
        ]);
    }

    /** Scan field / product picker. Cost only for users who may see it. */
    public function lookup(Request $request, ProductLookup $lookup): JsonResponse
    {
        $this->authorize('create', Purchase::class);

        // `type=raw_material|finished` narrows the GRN's segmented filter; anything else searches both kinds.
        $only = ProductItemType::tryFrom((string) $request->query('type', ''));

        $found = $lookup->find((string) $request->query('code', ''), $this->actor($request)->can('products.view_cost'), $only);
        $found['products'] = $this->withPurchaseCost($found['products']);

        return response()->json($found);
    }

    /**
     * The GRN prefills the unit cost: the current branch's last received purchase cost per unit
     * (paisa), else the product's cost price. The receiver is entering costs anyway, so this is
     * independent of `products.view_cost`.
     *
     * @param  list<array<string, mixed>>  $products
     * @return list<array<string, mixed>>
     */
    private function withPurchaseCost(array $products): array
    {
        $ids = array_map(static fn (array $p): int => (int) $p['id'], $products);
        if ($ids === []) {
            return $products;
        }

        $latest = PurchaseItem::query()
            ->whereIn('purchase_items.product_id', $ids)
            ->join('purchases', 'purchases.id', '=', 'purchase_items.purchase_id')
            ->where('purchases.branch_id', app(TenantContext::class)->branchId())
            ->where('purchases.tenant_id', app(TenantContext::class)->tenantId())
            ->where('purchases.status', PurchaseStatus::Received->value)
            ->whereNull('purchases.deleted_at')
            ->selectRaw('purchase_items.product_id, max(purchase_items.id) as item_id')
            ->groupBy('purchase_items.product_id')
            ->pluck('item_id', 'product_id');
        $costs = PurchaseItem::query()->whereIn('id', $latest->values()->all())->get(['id', 'unit_cost'])
            ->mapWithKeys(static fn (PurchaseItem $i): array => [$i->id => $i->unit_cost->paisa]);
        $fallback = Product::query()->whereIn('id', $ids)->get(['id', 'cost_price'])
            ->mapWithKeys(static fn (Product $p): array => [$p->id => $p->cost_price->paisa]);

        return array_map(static function (array $p) use ($latest, $costs, $fallback): array {
            $id = (int) $p['id'];
            $itemId = $latest->get($id);
            $p['purchase_cost'] = $itemId !== null ? (int) $costs->get($itemId, 0) : (int) $fallback->get($id, 0);

            return $p;
        }, $products);
    }

    public function store(ReceivePurchaseRequest $request, ReceivePurchase $receive): RedirectResponse
    {
        /** @var array<string, mixed> $v */
        $v = $request->validated();

        $items = [];
        /** @var list<array<string, mixed>> $lines */
        $lines = $v['items'];
        foreach ($lines as $line) {
            $items[] = [
                'product_id' => (int) $line['product_id'],
                'qty' => Quantity::ofMilli((int) $line['qty']),
                'unit_cost' => Money::ofPaisa((int) $line['unit_cost']),
                'discount' => Money::ofPaisa((int) ($line['discount'] ?? 0)),
                'batch_no' => $line['batch_no'] ?? null,
                'expires_at' => isset($line['expires_at']) ? Carbon::parse((string) $line['expires_at']) : null,
            ];
        }

        $input = [
            'vendor_id' => isset($v['vendor_id']) ? (int) $v['vendor_id'] : null,
            'items' => $items,
            'vendor_ref' => $v['vendor_ref'] ?? null,
            'purchased_at' => isset($v['purchased_at']) ? Carbon::parse((string) $v['purchased_at']) : null,
            'discount' => Money::ofPaisa((int) ($v['discount'] ?? 0)),
            'shipping' => Money::ofPaisa((int) ($v['shipping'] ?? 0)),
            'note' => $v['note'] ?? null,
        ];
        if (isset($v['payment']) && is_array($v['payment'])) {
            abort_unless($this->actor($request)->can('payments.pay'), 403);
            $input['payment'] = [
                'amount' => Money::ofPaisa((int) $v['payment']['amount']),
                'payment_method_id' => (int) $v['payment']['payment_method_id'],
                'reference' => $v['payment']['reference'] ?? null,
            ];
        }

        try {
            $purchase = $receive->handle($this->actor($request), $input);
        } catch (DuplicateVendorBill $e) {
            throw ValidationException::withMessages(['vendor_ref' => $e->getMessage()]);
        } catch (DomainException $e) {
            throw ValidationException::withMessages(['items' => $e->getMessage()]);
        }
        Inertia::flash('toast', ['type' => 'success', 'message' => __('Purchase received.')]);

        return to_route('purchases.show', $purchase);
    }

    public function show(Request $request, Purchase $purchase): Response
    {
        $this->authorize('view', $purchase);
        $tz = $this->timezone();
        $purchase->load(['vendor:id,uuid,name,balance,phone', 'branch:id,name,code', 'items.product:id,name,sku,variant_label,sale_price']);

        // A received GRN's batches point at its lines (`purchase` source). The legacy import books the remainder of an
        // imported bill as an `opening` batch on the same line, so those count too (product + line guard against id reuse).
        $itemIds = $purchase->items->pluck('id')->all();
        $productIds = $purchase->items->pluck('product_id')->all();
        $batches = StockBatch::query()->withoutBranchScope()->where('branch_id', $purchase->branch_id)
            ->whereIn('source_type', [StockSourceType::Purchase, StockSourceType::Opening])
            ->whereIn('source_id', $itemIds)->whereIn('product_id', $productIds)->orderBy('id')->get();
        $createdBy = $purchase->created_by === null ? null : User::query()->whereKey($purchase->created_by)->value('name');
        $payments = PaymentAllocation::query()
            ->where('allocatable_type', AllocatableType::Purchase)->where('allocatable_id', $purchase->id)
            ->with('payment:id,number,paid_at,reference')->orderBy('id')->get();

        return Inertia::render('purchases/show', [
            'purchase' => [
                'id' => $purchase->id,
                'uuid' => $purchase->uuid,
                'number' => $purchase->number,
                'vendor_id' => $purchase->vendor_id,
                'vendor_uuid' => $purchase->vendor->uuid ?? null,
                'vendor' => $purchase->vendor->name ?? null,
                'vendor_phone' => $purchase->vendor->phone ?? null,
                'vendor_balance' => $purchase->vendor instanceof Vendor ? $purchase->vendor->balance->paisa : 0,
                'vendor_ref' => $purchase->vendor_ref,
                'branch' => $purchase->branch instanceof Branch ? ['name' => $purchase->branch->name, 'code' => (string) $purchase->branch->code] : null,
                'received_by' => is_string($createdBy) ? $createdBy : null,
                'imported' => $purchase->legacy_id !== null,
                'status' => $purchase->status->value,
                'payment_status' => $purchase->payment_status->value,
                'purchased_at' => BusinessDay::localDate($purchase->purchased_at, $tz),
                'subtotal' => $purchase->subtotal->paisa,
                'discount' => $purchase->discount->paisa,
                'tax_total' => $purchase->tax_total->paisa,
                'shipping' => $purchase->shipping->paisa,
                'total' => $purchase->total->paisa,
                'paid' => $purchase->paid->paisa,
                'due' => $purchase->due->paisa,
                'note' => $purchase->note,
            ],
            'items' => $purchase->items->map(static fn ($i): array => [
                'id' => $i->id,
                'product' => $i->product->name ?? '',
                'variant_label' => $i->product?->variant_label,
                'sku' => $i->product->sku ?? '',
                'qty' => $i->qty->milli,
                'unit_cost' => $i->unit_cost->paisa,
                'discount' => $i->discount->paisa,
                'line_total' => $i->line_total->paisa,
                'sale_price' => $i->product instanceof Product ? $i->product->sale_price->paisa : null,
            ])->all(),
            'batches' => $batches->map(static fn (StockBatch $b): array => [
                'id' => $b->id,
                'product_id' => $b->product_id,
                'product' => $purchase->items->firstWhere('product_id', $b->product_id)?->product->name ?? '',
                'sku' => $purchase->items->firstWhere('product_id', $b->product_id)?->product->sku ?? '',
                'received_at' => BusinessDay::localDate($b->received_at, $tz),
                'batch_no' => $b->batch_no,
                'unit_cost' => $b->unit_cost->paisa,
                'qty_received' => $b->qty_received->milli,
                'qty_remaining' => $b->qty_remaining->milli,
                'expires_at' => $b->expires_at?->toDateString(),
            ])->all(),
            'payments' => $payments->map(static fn (PaymentAllocation $a): array => [
                'id' => $a->id,
                'number' => $a->payment->number ?? '',
                'paid_at' => BusinessDay::localDate($a->payment?->paid_at, $tz),
                'amount' => $a->amount->paisa,
                'reference' => $a->payment->reference ?? null,
            ])->all(),
            'paymentMethods' => PaymentMethod::query()->forVendorPayments()->orderBy('sort_order')->get(['id', 'name', 'requires_reference']),
            'can' => [
                'cancel' => $this->actor($request)->can('cancel', $purchase),
                'pay' => $this->actor($request)->can('payments.pay'),
            ],
        ]);
    }

    public function cancel(Request $request, Purchase $purchase, CancelPurchase $cancel): RedirectResponse
    {
        $this->authorize('cancel', $purchase);
        $this->guarded(fn (): Purchase => $cancel->handle($this->actor($request), $purchase));
        Inertia::flash('toast', ['type' => 'success', 'message' => __('Purchase cancelled.')]);

        return to_route('purchases.show', $purchase);
    }

    public function pay(PayVendorRequest $request, Purchase $purchase, PayVendor $pay): RedirectResponse
    {
        $this->authorize('view', $purchase);
        abort_if($purchase->vendor_id === null, 422, __('purchases.vendorless_pay'));
        $amount = Money::ofPaisa((int) $request->validated('amount'));

        $this->guarded(fn (): Payment => $pay->handle($this->actor($request), [
            'vendor_id' => $purchase->vendor_id,
            'amount' => $amount,
            'payment_method_id' => (int) $request->validated('payment_method_id'),
            'allocations' => [['purchase_id' => $purchase->id, 'amount' => $amount]],
            'reference' => $request->validated('reference'),
            'note' => $request->validated('note'),
        ]), 'amount');
        Inertia::flash('toast', ['type' => 'success', 'message' => __('Payment recorded.')]);

        return back();
    }

    private function timezone(): string
    {
        return BusinessDay::timezone(app(TenantContext::class)->tenant());
    }

    private function actor(Request $request): User
    {
        $user = $request->user();
        abort_unless($user instanceof User, 401);

        return $user;
    }
}
