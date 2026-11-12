<?php

declare(strict_types=1);

namespace App\Http\Controllers\Sales;

use App\Actions\Sales\CollectCustomerPayment;
use App\Actions\Sales\Data\CustomerPaymentDraft;
use App\Enums\PartyType;
use App\Enums\PaymentDirection;
use App\Enums\SaleStatus;
use App\Http\Controllers\Concerns\TranslatesDomainErrors;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Sales\Concerns\PresentsSales;
use App\Http\Requests\Sales\CollectPaymentRequest;
use App\Messaging\MessagingUi;
use App\Models\Customer;
use App\Models\CustomerGroup;
use App\Models\Payment;
use App\Models\PaymentMethod;
use App\Models\Sale;
use App\Reports\ColumnType;
use App\Reports\ReportColumn;
use App\Reports\ReportResult;
use App\Sms\SmsNotice;
use App\Sms\Templates;
use App\Support\Export\ExportFormat;
use App\Support\Export\ExportMeta;
use App\Support\Export\TabularExport;
use App\Support\Http\TableQuery;
use App\Support\Money\Money;
use App\Support\Sales\BusinessDay;
use App\Support\Sales\CustomerAccounts;
use App\Support\Sales\DueAging;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response as HttpResponse;
use Illuminate\Support\Carbon;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Open dues of the current branch: customers with aging buckets (0–30, 31–60, 61–90, 90+ days),
 * a customer drill-down with its open invoices, and the collect dialog (CollectCustomerPayment).
 */
final class DueController extends Controller
{
    use AuthorizesRequests, PresentsSales, TranslatesDomainErrors;

    /** One CASE per bucket; the bindings come from bucketBindings() in the same order. */
    private const array BUCKET_CASE = [
        'd0_30' => 'CASE WHEN sold_at >= ? THEN due ELSE 0 END',
        'd31_60' => 'CASE WHEN sold_at >= ? AND sold_at < ? THEN due ELSE 0 END',
        'd61_90' => 'CASE WHEN sold_at >= ? AND sold_at < ? THEN due ELSE 0 END',
        'd90_plus' => 'CASE WHEN sold_at < ? THEN due ELSE 0 END',
    ];

    private const string BUCKET_SQL = 'SUM(CASE WHEN sold_at >= ? THEN due ELSE 0 END) AS d0_30, '
        .'SUM(CASE WHEN sold_at >= ? AND sold_at < ? THEN due ELSE 0 END) AS d31_60, '
        .'SUM(CASE WHEN sold_at >= ? AND sold_at < ? THEN due ELSE 0 END) AS d61_90, '
        .'SUM(CASE WHEN sold_at < ? THEN due ELSE 0 END) AS d90_plus';

    private const string DEBTOR_SQL = 'COUNT(DISTINCT CASE WHEN sold_at >= ? THEN customer_id END) AS d0_30, '
        .'COUNT(DISTINCT CASE WHEN sold_at >= ? AND sold_at < ? THEN customer_id END) AS d31_60, '
        .'COUNT(DISTINCT CASE WHEN sold_at >= ? AND sold_at < ? THEN customer_id END) AS d61_90, '
        .'COUNT(DISTINCT CASE WHEN sold_at < ? THEN customer_id END) AS d90_plus';

    public function index(Request $request): Response
    {
        $this->authorize('viewAny', Customer::class);

        $table = $this->listTable($request);
        $bindings = $this->bucketBindings();
        $query = $this->listQuery($table, $bindings);
        $page = $table->paginate($query);
        $accounts = app(CustomerAccounts::class);

        /** @var list<Sale> $groups */
        $groups = $page->items();
        $customers = Customer::query()->withTrashed()
            ->whereIn('id', array_map(static fn (Sale $g): int => (int) $g->getAttribute('customer_id'), $groups))
            ->get()->keyBy('id');
        $lastSale = Sale::query()->where('status', SaleStatus::Completed)
            ->whereIn('customer_id', $customers->keys()->all())
            ->groupBy('customer_id')
            ->selectRaw('customer_id, MAX(sold_at) AS last_at')
            ->toBase()->pluck('last_at', 'customer_id');

        $totals = $this->openDues()->selectRaw('COUNT(DISTINCT customer_id) AS customers, COUNT(*) AS invoices, COALESCE(SUM(due), 0) AS total_due, '.self::BUCKET_SQL, $bindings)->toBase()->first();
        // Debtors per aging bucket (the tiles' "N debtors"); same bindings as BUCKET_SQL.
        $debtors = $this->openDues()->selectRaw(self::DEBTOR_SQL, $bindings)->toBase()->first();

        return Inertia::render('dues/index', [
            'rows' => array_map(function (Sale $g) use ($customers, $lastSale, $accounts): array {
                $customer = $customers->get((int) $g->getAttribute('customer_id'));
                $oldest = $g->getAttribute('oldest_at');

                return [
                    'customer' => $customer instanceof Customer ? [
                        ...($this->customerSummary($customer) ?? []),
                        'name_bn' => $customer->name_bn,
                        'balance' => $customer->balance->paisa,
                        'credit_limit' => $accounts->creditLimit($customer)?->paisa,
                    ] : null,
                    'invoices' => (int) $g->getAttribute('invoices'),
                    'total_due' => (int) $g->getAttribute('total_due'),
                    'oldest_at' => $this->utcTime($oldest),
                    'buckets' => $this->bucketsOf($g->getAttributes()),
                    'last_sale_at' => $this->utcTime($lastSale->get((int) $g->getAttribute('customer_id'))),
                ];
            }, $groups),
            'meta' => ['page' => $page->currentPage(), 'perPage' => $page->perPage(), 'total' => $page->total(), 'lastPage' => $page->lastPage()],
            'filters' => [...$table->filters, 'q' => $table->search],
            'totals' => [
                'customers' => (int) ($totals->customers ?? 0),
                'invoices' => (int) ($totals->invoices ?? 0),
                'total_due' => (int) ($totals->total_due ?? 0),
                'buckets' => $this->bucketsOf((array) $totals),
                'debtors' => $this->bucketsOf((array) $debtors),
            ],
            'groups' => fn (): array => array_values(CustomerGroup::query()->where('is_active', true)->orderBy('name')->get(['id', 'name'])
                ->map(static fn (CustomerGroup $g): array => ['id' => $g->id, 'name' => $g->name])->all()),
            'messaging' => fn (): array => app(MessagingUi::class)->props($this->actor($request)),
            // The collect dialog for one row (reloaded alone with ?collect=<customer uuid>).
            'collect' => Inertia::optional(fn (): ?array => $this->collectPayload($request)),
        ]);
    }

    private function listTable(Request $request): TableQuery
    {
        return TableQuery::fromRequest($request, ['total_due', 'oldest_at', 'invoices'], ['bucket', 'group', 'credit', 'from', 'to', 'period'], '-total_due');
    }

    /**
     * Open dues grouped per customer with the page's filters (same query string), shared by the page and the export.
     *
     * @param  list<CarbonImmutable>  $bindings
     * @return Builder<Sale>
     */
    private function listQuery(TableQuery $table, array $bindings): Builder
    {
        $query = $this->openDues()
            ->selectRaw('customer_id, COUNT(*) AS invoices, SUM(due) AS total_due, MIN(sold_at) AS oldest_at, '.self::BUCKET_SQL, $bindings)
            ->groupBy('customer_id');
        $this->dateRange($query, 'sold_at', $table->filters['from'] ?? null, $table->filters['to'] ?? null, $table->filters['period'] ?? null);
        if ($table->search !== '') {
            $query->whereIn('customer_id', Customer::query()->withTrashed()->select('id')->search($table->search));
        }
        if (isset($table->filters['group'])) {
            $query->whereIn('customer_id', Customer::query()->withTrashed()->select('id')->where('customer_group_id', (int) $table->filters['group']));
        }
        // Credit utilisation of the customer's account balance against the effective credit limit
        // (the customer's own limit, else the tenant default; as CustomerAccounts::creditLimit).
        $default = $this->defaultCreditLimit();
        $limit = 'COALESCE(credit_limit, ?)';
        match ($table->filters['credit'] ?? null) {
            'near_limit' => $query->whereIn('customer_id', Customer::query()->withTrashed()->select('id')
                ->whereRaw("{$limit} IS NOT NULL AND balance * 100 > {$limit} * 75", [$default, $default])),
            'over_limit' => $query->whereIn('customer_id', Customer::query()->withTrashed()->select('id')
                ->whereRaw("{$limit} IS NOT NULL AND balance > {$limit}", [$default, $default])),
            default => null,
        };
        $bucket = $table->filters['bucket'] ?? null;
        if (in_array($bucket, DueAging::BUCKETS, true)) {
            $query->havingRaw('SUM('.self::BUCKET_CASE[$bucket].') > 0', $this->bucketBindings($bucket));
        }

        return $query;
    }

    /** GET /app/dues/export.{csv|xlsx|pdf}: every debtor of the filtered list, same query string as the page. */
    public function export(Request $request, string $format, TabularExport $export, TenantContext $context): HttpResponse|StreamedResponse
    {
        $this->authorize('viewAny', Customer::class);
        $table = $this->listTable($request);
        /** @var Collection<int, Sale> $groups */
        $groups = $table->applySort($this->listQuery($table, $this->bucketBindings()))->get();
        $accounts = app(CustomerAccounts::class);
        $tz = $this->timezone();

        $customers = Customer::query()->withTrashed()
            ->whereIn('id', $groups->map(static fn (Sale $g): int => (int) $g->getAttribute('customer_id'))->all())
            ->get()->keyBy('id');
        $lastSale = Sale::query()->where('status', SaleStatus::Completed)
            ->whereIn('customer_id', $customers->keys()->all())
            ->groupBy('customer_id')
            ->selectRaw('customer_id, MAX(sold_at) AS last_at')
            ->toBase()->pluck('last_at', 'customer_id');

        $columns = [
            new ReportColumn('customer_no', 'Customer No', ColumnType::Text),
            new ReportColumn('name', 'Customer', ColumnType::Text),
            new ReportColumn('shop_name', 'Shop', ColumnType::Text),
            new ReportColumn('phone', 'Phone', ColumnType::Text),
            new ReportColumn('invoices', 'Invoices', ColumnType::Int),
            new ReportColumn('total_due', 'Total due', ColumnType::Money),
            new ReportColumn('credit_limit', 'Credit limit', ColumnType::Money),
            new ReportColumn('oldest', 'Oldest invoice', ColumnType::Date),
            new ReportColumn('last_sale', 'Last sale', ColumnType::Date),
        ];
        $rows = [];
        $totals = ['invoices' => 0, 'total_due' => 0];
        foreach ($groups as $g) {
            $customer = $customers->get((int) $g->getAttribute('customer_id'));
            $summary = $customer instanceof Customer ? $this->customerSummary($customer) : null;
            $lastAt = $lastSale->get((int) $g->getAttribute('customer_id'));
            $oldest = $g->getAttribute('oldest_at');
            $row = [
                'customer_no' => $summary['customer_no'] ?? null,
                'name' => $summary['name'] ?? null,
                'shop_name' => $summary['shop_name'] ?? null,
                'phone' => $summary['phone'] ?? null,
                'invoices' => (int) $g->getAttribute('invoices'),
                'total_due' => (int) $g->getAttribute('total_due'),
                'credit_limit' => $customer instanceof Customer ? $accounts->creditLimit($customer)?->paisa : null,
                'oldest' => is_string($oldest) ? BusinessDay::localDate(CarbonImmutable::parse($oldest, 'UTC'), $tz) : null,
                'last_sale' => is_string($lastAt) ? BusinessDay::localDate(CarbonImmutable::parse($lastAt, 'UTC'), $tz) : null,
            ];
            $rows[] = $row;
            $totals['invoices'] += $row['invoices'];
            $totals['total_due'] += $row['total_due'];
        }

        return $export->respond(new ReportResult($columns, $rows, $totals), ExportFormat::from($format), 'dues-'.now($tz)->format('Ymd-His'), ExportMeta::for($context, 'Customer dues'));
    }

    public function show(Request $request, string $customer): Response
    {
        $model = Customer::query()->where('uuid', $customer)->firstOrFail();
        $this->authorize('view', $model);
        $actor = $this->actor($request);

        $tz = $this->timezone();
        $now = Carbon::now();
        $invoices = $this->openDues()->where('customer_id', $model->id)->orderBy('sold_at')->orderBy('id')->get();

        $amount = $request->integer('amount');
        $preview = $amount > 0 ? DueAging::allocateOldestFirst(
            array_values($invoices->map(static fn (Sale $s): array => ['id' => $s->id, 'due' => $s->due])->all()),
            Money::ofPaisa($amount),
        ) : null;

        $payments = Payment::query()
            ->with('paymentMethod')
            ->where('party_type', PartyType::Customer)
            ->where('party_id', $model->id)
            ->where('direction', PaymentDirection::In)
            ->whereNull('voided_at')
            ->latest('paid_at')->latest('id')
            ->limit(10)
            ->get()
            ->filter(static fn (Payment $p): bool => ! $p->paymentMethod instanceof PaymentMethod || ! CustomerAccounts::isCredit($p->paymentMethod));

        $receiptId = $request->integer('receipt');

        return Inertia::render('dues/show', [
            'customer' => [
                ...($this->customerSummary($model) ?? []),
                'balance' => $model->balance->paisa,
                'credit_limit' => app(CustomerAccounts::class)->creditLimit($model)?->paisa,
                'advance' => app(CustomerAccounts::class)->advance($model)->paisa,
            ],
            'invoices' => array_values($invoices->map(function (Sale $s) use ($now, $tz): array {
                $age = DueAging::age($s->sold_at, $now, $tz);

                return [
                    'id' => $s->id,
                    'number' => (string) $s->number,
                    'sold_at' => $this->localTime($s->sold_at),
                    'total' => $s->total->paisa,
                    'paid' => $s->paid->paisa,
                    'due' => $s->due->paisa,
                    'age' => $age,
                    'bucket' => DueAging::bucket($age),
                ];
            })->all()),
            'payments' => array_values($payments->map(fn (Payment $p): array => [
                'id' => $p->id,
                'number' => $p->number,
                'paid_at' => $this->localTime($p->paid_at),
                'method' => $p->paymentMethod instanceof PaymentMethod ? $p->paymentMethod->name : '',
                'amount' => $p->amount->paisa,
                'reference' => $p->reference,
            ])->all()),
            'preview' => $preview !== null ? [
                'amount' => $amount,
                'allocations' => array_map(static fn (Money $m): int => $m->paisa, $preview['allocations']),
                'advance' => $preview['advance']->paisa,
            ] : null,
            'receipt' => $receiptId > 0 && Payment::query()->whereKey($receiptId)->where('party_id', $model->id)->exists() ? $receiptId : null,
            'methods' => $this->moneyMethods(),
            'can' => ['collect' => $actor->can('collect', Payment::class) && ! $model->is_walk_in],
            'messaging' => fn (): array => app(MessagingUi::class)->props($actor),
            'last_payment' => fn (): ?array => $receiptId > 0 ? $this->lastPayment($model, $receiptId) : null,
        ]);
    }

    public function store(CollectPaymentRequest $request, string $customer, CollectCustomerPayment $collect): RedirectResponse
    {
        $model = Customer::query()->where('uuid', $customer)->firstOrFail();
        $this->authorize('view', $model);
        $this->authorize('collect', Payment::class);

        $allocations = null;
        if ($request->filled('allocations')) {
            $allocations = [];
            foreach ((array) $request->input('allocations') as $row) {
                if (is_array($row)) {
                    $allocations[(int) $row['sale_id']] = Money::ofPaisa((int) $row['amount']);
                }
            }
        }

        $amount = Money::ofPaisa($request->integer('amount'));
        $payment = $this->guarded(fn (): Payment => $collect->handle($this->actor($request), new CustomerPaymentDraft(
            customerId: $model->id,
            amount: $amount,
            paymentMethodId: $request->integer('payment_method_id'),
            allocations: $allocations,
            reference: $request->string('reference')->trim()->value() ?: null,
            note: $request->string('note')->trim()->value() ?: null,
        )), 'amount');

        Inertia::flash('toast', ['type' => 'success', 'message' => __('receipts.flash.collected', ['number' => $payment->number, 'amount' => $amount->format(app()->getLocale())])]);
        SmsNotice::flash(Templates::PAYMENT_RECEIVED, $model);

        return to_route('dues.show', ['customer' => $model->uuid, 'receipt' => $payment->id]);
    }

    /** @return array<string, mixed>|null */
    private function collectPayload(Request $request): ?array
    {
        $model = Customer::query()->where('uuid', $request->string('collect')->value())->first();
        if (! $model instanceof Customer || ! $this->actor($request)->can('view', $model)) {
            return null;
        }

        return [
            'customer' => [
                ...($this->customerSummary($model) ?? []),
                'balance' => $model->balance->paisa,
                'credit_limit' => app(CustomerAccounts::class)->creditLimit($model)?->paisa,
            ],
            'invoices' => array_values($this->openDues()->where('customer_id', $model->id)->orderBy('sold_at')->orderBy('id')->get()
                ->map(fn (Sale $s): array => [
                    'id' => $s->id,
                    'number' => (string) $s->number,
                    'sold_at' => $this->localTime($s->sold_at),
                    'due' => $s->due->paisa,
                ])->all()),
            'methods' => $this->moneyMethods(),
            'can' => ['collect' => $this->actor($request)->can('collect', Payment::class) && ! $model->is_walk_in],
        ];
    }

    /**
     * The amount of the receipt just collected, for the WhatsApp thank-you text ({amount}).
     *
     * @return array{id: int, amount: int}|null
     */
    private function lastPayment(Customer $customer, int $receiptId): ?array
    {
        $payment = Payment::query()->whereKey($receiptId)->where('party_type', PartyType::Customer)->where('party_id', $customer->id)->first();

        return $payment instanceof Payment ? ['id' => $payment->id, 'amount' => $payment->amount->paisa] : null;
    }

    private function defaultCreditLimit(): ?int
    {
        $default = app(TenantContext::class)->tenant()->setting('default_credit_limit');

        return is_int($default) ? $default : null;
    }

    /** A raw UTC timestamp from an aggregate (MIN / MAX) in tenant-local time. */
    private function utcTime(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $this->localTime(Carbon::parse($value, 'UTC')) : null;
    }

    /** @return Builder<Sale> */
    private function openDues(): Builder
    {
        return Sale::query()->where('status', SaleStatus::Completed)->where('due', '>', 0);
    }

    /** @return list<CarbonImmutable> UTC instants (start of the local day N days ago); all buckets in order when $bucket is null. */
    private function bucketBindings(?string $bucket = null): array
    {
        $today = CarbonImmutable::now($this->timezone())->startOfDay();
        $edge = static fn (int $days): CarbonImmutable => $today->subDays($days)->utc();

        return match ($bucket) {
            null => [$edge(30), $edge(60), $edge(30), $edge(90), $edge(60), $edge(90)],
            'd0_30' => [$edge(30)],
            'd31_60' => [$edge(60), $edge(30)],
            'd61_90' => [$edge(90), $edge(60)],
            default => [$edge(90)],
        };
    }

    /**
     * @param  array<array-key, mixed>  $row
     * @return array<string, int>
     */
    private function bucketsOf(array $row): array
    {
        $out = [];
        foreach (DueAging::BUCKETS as $bucket) {
            $out[$bucket] = (int) ($row[$bucket] ?? 0);
        }

        return $out;
    }
}
