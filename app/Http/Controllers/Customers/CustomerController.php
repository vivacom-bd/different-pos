<?php

declare(strict_types=1);

namespace App\Http\Controllers\Customers;

use App\Actions\Customers\DeactivateCustomer;
use App\Actions\Customers\MergeCustomers;
use App\Actions\Customers\SaveCustomer;
use App\Enums\CardStatus;
use App\Enums\PartyType;
use App\Enums\SaleStatus;
use App\Exceptions\Domain\DuplicateCustomerPhone;
use App\Exceptions\Domain\InvalidPartyData;
use App\Http\Controllers\Controller;
use App\Http\Requests\Customers\MergeCustomerRequest;
use App\Http\Requests\Customers\SaveCustomerRequest;
use App\Messaging\MessagingUi;
use App\Models\Customer;
use App\Models\CustomerGroup;
use App\Models\MembershipCard;
use App\Models\Message;
use App\Models\Payment;
use App\Models\Sale;
use App\Models\SaleReturn;
use App\Models\User;
use App\Reports\ColumnType;
use App\Reports\ReportColumn;
use App\Reports\ReportResult;
use App\Support\Cards\CardBrand;
use App\Support\Customers\CustomerNumber;
use App\Support\Customers\CustomerPin;
use App\Support\Customers\CustomerSearch;
use App\Support\Customers\CustomerSearchResult;
use App\Support\Export\ExportFormat;
use App\Support\Export\ExportMeta;
use App\Support\Export\TabularExport;
use App\Support\Geo\GeoCatalog;
use App\Support\Http\RecencyFilter;
use App\Support\Http\TableQuery;
use App\Support\Money\Money;
use App\Support\Phone\BdPhone;
use App\Support\Sales\BusinessDay;
use App\Support\Sales\CustomerAccounts;
use App\Support\Tenancy\BranchScope;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response as HttpResponse;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Customers: list, create/edit, profile (card panel + history tabs), deactivate, merge and the two
 * small JSON helpers (picker search, live duplicate-phone check). Customers bind by uuid here.
 */
final class CustomerController extends Controller
{
    private const int TAB_LIMIT = 50;

    public function __construct(private readonly CustomerSearch $search, private readonly GeoCatalog $geo) {}

    public function index(Request $request): Response
    {
        Gate::authorize('viewAny', Customer::class);

        return Inertia::render('customers/index', [...$this->listProps($request), 'dialog' => null]);
    }

    /** The list with the add-customer dialog open (deep link: /app/customers/create). */
    public function create(Request $request): Response
    {
        Gate::authorize('create', Customer::class);

        return Inertia::render('customers/index', [...$this->listProps($request), 'dialog' => 'create']);
    }

    /** GET /app/customers/export.{csv|xlsx|pdf}: the filtered list (same query string as the page). */
    public function export(Request $request, string $format, TabularExport $export, TenantContext $context): HttpResponse|StreamedResponse
    {
        Gate::authorize('viewAny', Customer::class);
        $query = $this->listQuery($this->table($request))->with(['customerGroup', 'membershipCards'])->orderBy('name')->orderBy('id');
        $tz = $this->timezone();

        $columns = [
            new ReportColumn('customer_no', 'Customer No', ColumnType::Text),
            new ReportColumn('name', 'Name', ColumnType::Text),
            new ReportColumn('name_bn', 'Name BN', ColumnType::Text),
            new ReportColumn('shop_name', 'Shop', ColumnType::Text),
            new ReportColumn('phone', 'Phone', ColumnType::Text),
            new ReportColumn('district', 'District', ColumnType::Text),
            new ReportColumn('upazila', 'Upazila / Thana', ColumnType::Text),
            new ReportColumn('area', 'Area', ColumnType::Text),
            new ReportColumn('address', 'Address', ColumnType::Text),
            new ReportColumn('post_code', 'Post code', ColumnType::Text),
            new ReportColumn('group', 'Group', ColumnType::Text),
            new ReportColumn('balance', 'Balance', ColumnType::Money),
            new ReportColumn('credit_limit', 'Credit limit', ColumnType::Money),
            new ReportColumn('card_status', 'Card status', ColumnType::Text),
            new ReportColumn('last_purchase', 'Last purchase', ColumnType::Date),
        ];
        $rows = [];
        $balance = 0;
        foreach ($query->lazy(500) as $c) {
            /** @var Customer $c */
            $row = $this->row($c);
            $balance += $c->balance->paisa;
            $rows[] = [
                'customer_no' => (string) $row['customer_no'], 'name' => $c->name, 'name_bn' => (string) $c->name_bn, 'shop_name' => (string) $c->shop_name,
                'phone' => (string) $c->phone, 'district' => (string) ($row['location']['district']['name_en'] ?? ''), 'upazila' => (string) ($row['location']['upazila']['name_en'] ?? ''), 'area' => (string) $c->area, 'address' => (string) $c->address_line, 'post_code' => (string) $c->post_code,
                'group' => (string) $row['group'], 'balance' => $c->balance->paisa, 'credit_limit' => is_int($row['credit_limit']) ? $row['credit_limit'] : null,
                'card_status' => (string) $row['card_status'], 'last_purchase' => BusinessDay::localDate($c->last_purchase_at, $tz),
            ];
        }

        return $export->respond(
            new ReportResult($columns, $rows, ['balance' => $balance]),
            ExportFormat::from($format),
            'customers-'.now($tz)->format('Ymd-His'),
            ExportMeta::for($context, 'Customers'),
        );
    }

    public function store(SaveCustomerRequest $request, SaveCustomer $save): RedirectResponse|JsonResponse
    {
        Gate::authorize('create', Customer::class);

        try {
            $customer = $save->handle($this->actor($request), $request->customerData());
        } catch (DuplicateCustomerPhone $e) {
            return $this->duplicate($request, $e);
        } catch (InvalidPartyData $e) {
            return $this->invalid($request, $e);
        }
        Inertia::flash('toast', ['type' => 'success', 'message' => __('Customer saved.')]);

        return to_route('customers.show', $customer->uuid);
    }

    public function show(Request $request, string $customer): Response
    {
        $model = $this->find($customer);
        Gate::authorize('view', $model);

        return Inertia::render('customers/show', [...$this->showProps($request, $model), 'editing' => false]);
    }

    /** The profile with the edit dialog open (deep link: /app/customers/{uuid}/edit). */
    public function edit(Request $request, string $customer): Response
    {
        $model = $this->find($customer);
        Gate::authorize('update', $model);

        return Inertia::render('customers/show', [...$this->showProps($request, $model), 'editing' => true]);
    }

    public function update(SaveCustomerRequest $request, string $customer, SaveCustomer $save): RedirectResponse|JsonResponse
    {
        $model = $this->find($customer);
        Gate::authorize('update', $model);

        try {
            $save->handle($this->actor($request), $request->customerData(), $model);
        } catch (DuplicateCustomerPhone $e) {
            return $this->duplicate($request, $e);
        } catch (InvalidPartyData $e) {
            return $this->invalid($request, $e);
        }
        Inertia::flash('toast', ['type' => 'success', 'message' => __('Customer saved.')]);

        return to_route('customers.show', $model->uuid);
    }

    public function deactivate(Request $request, string $customer, DeactivateCustomer $deactivate): RedirectResponse
    {
        $model = $this->find($customer);
        Gate::authorize('delete', $model);

        try {
            $deactivate->handle($this->actor($request), $model);
        } catch (InvalidPartyData $e) {
            return back()->withErrors(['form' => $e->getMessage()]);
        }
        Inertia::flash('toast', ['type' => 'success', 'message' => __('Customer deactivated.')]);

        return to_route('customers.index');
    }

    public function merge(MergeCustomerRequest $request, string $customer, MergeCustomers $merge): RedirectResponse
    {
        $loser = $this->find($customer);
        Gate::authorize('delete', $loser);
        $survivor = $this->find((string) $request->validated('into'));
        Gate::authorize('update', $survivor);

        try {
            $merge->handle($this->actor($request), $survivor, $loser);
        } catch (InvalidPartyData $e) {
            return back()->withErrors(['form' => $e->getMessage()]);
        }
        Inertia::flash('toast', ['type' => 'success', 'message' => __('Customers merged.')]);

        return to_route('customers.show', $survivor->uuid);
    }

    /** GET /app/customers/search?q=: the picker (number, phone suffix, name or shop name). */
    public function search(Request $request): JsonResponse
    {
        Gate::authorize('viewAny', Customer::class);
        $q = mb_substr(trim((string) $request->query('q', '')), 0, 60);

        return response()->json([
            'customers' => $this->search->search(ltrim($q, '#'))->map(static fn (CustomerSearchResult $c): array => [
                'uuid' => $c->uuid,
                'customer_no' => $c->customerNo,
                'name' => $c->name,
                'name_bn' => $c->nameBn,
                'shop_name' => $c->shopName,
                'phone' => $c->phone,
                'is_active' => $c->isActive,
            ])->values(),
        ]);
    }

    /** GET /app/customers/check-phone?phone=&except=: the customer already using this number, if any. */
    public function checkPhone(Request $request): JsonResponse
    {
        Gate::authorize('viewAny', Customer::class);
        $phone = BdPhone::toE164((string) $request->query('phone', ''));
        $existing = null;
        if ($phone !== null) {
            $except = (string) $request->query('except', '');
            $existing = Customer::query()->where('phone', $phone)
                ->when($except !== '', fn (Builder $q) => $q->where('uuid', '!=', $except))
                ->first();
        }

        return response()->json(['existing' => $existing instanceof Customer ? $this->brief($existing) : null]);
    }

    private function actor(Request $request): User
    {
        $user = $request->user();
        abort_unless($user instanceof User, 401);

        return $user;
    }

    private function table(Request $request): TableQuery
    {
        return TableQuery::fromRequest(
            $request,
            ['name', 'customer_no', 'phone', 'area', 'balance', 'credit_limit', 'last_purchase_at'],
            ['group', 'card', 'status', 'due', 'view', 'balance', 'date', 'pin', 'district', 'upazila'],
            'name',
        );
    }

    /**
     * The list query for the page, its totals and the CSV. Filters: `view` (overdue | inactive),
     * `group`, `card` (yes | no), `balance` (due | zero | advance), `date` (last purchase: today | 7d |
     * 30d | 90d | year), `status=inactive` and `due=1` (older links).
     *
     * @return Builder<Customer>
     */
    private function listQuery(TableQuery $table): Builder
    {
        $f = $table->filters;
        $query = Customer::query()->where('is_walk_in', false);
        $inactive = ($f['status'] ?? null) === 'inactive' || ($f['view'] ?? null) === 'inactive';
        $query->where('is_active', ! $inactive);
        if (isset($f['group'])) {
            $query->where('customer_group_id', (int) $f['group']);
        }
        if (isset($f['district']) && preg_match('/^[0-9]+$/', (string) $f['district']) === 1) {
            $query->where('geo_district_id', (int) $f['district']);
        }
        if (isset($f['upazila']) && preg_match('/^[0-9]+$/', (string) $f['upazila']) === 1) {
            $query->where('geo_upazila_id', (int) $f['upazila']);
        }
        if (isset($f['card'])) {
            $f['card'] === 'yes'
                ? $query->whereHas('membershipCards', fn (Builder $q) => $q->where('status', CardStatus::Active))
                : $query->whereDoesntHave('membershipCards', fn (Builder $q) => $q->where('status', CardStatus::Active));
        }
        $balance = $f['balance'] ?? ((isset($f['due']) || ($f['view'] ?? null) === 'overdue') ? 'due' : null);
        match ($balance) {
            'due' => $query->where('balance', '>', 0),
            'zero' => $query->where('balance', 0),
            'advance' => $query->where('balance', '<', 0),
            default => null,
        };
        // Security PIN status: set | pending (none yet) | locked (wrong tries, still within the lock window).
        match ($f['pin'] ?? null) {
            'set' => $query->whereNotNull('pin_hash')->where(fn (Builder $q) => $q->whereNull('pin_locked_until')->orWhere('pin_locked_until', '<=', now())),
            'pending' => $query->whereNull('pin_hash'),
            'locked' => $query->where('pin_locked_until', '>', now()),
            default => null,
        };
        // Last purchase since the start of the tenant's business day / local year (RecencyFilter).
        $recency = in_array($f['date'] ?? null, ['today', '7d', '30d', '90d', 'year'], true)
            ? RecencyFilter::resolve($f['date'], $this->timezone())
            : null;
        if ($recency !== null) {
            $query->where('last_purchase_at', '>=', $recency[0]);
        }
        if ($table->search !== '') {
            $this->applySearch($query, $table->search);
        }

        return $query;
    }

    /** @return array<string, mixed> */
    private function listProps(Request $request): array
    {
        $table = $this->table($request);
        $query = $this->listQuery($table);
        $sums = (clone $query)->toBase()
            ->selectRaw('COUNT(*) as n, COALESCE(SUM(balance), 0) as balance, COALESCE(SUM(COALESCE(credit_limit, ?)), 0) as credit_limit', [$this->defaultCreditLimit() ?? 0])
            ->first();

        $page = $table->paginate($query->with(['customerGroup', 'membershipCards']));
        /** @var list<Customer> $items */
        $items = $page->items();

        return [
            'customers' => array_map($this->row(...), $items),
            'meta' => ['page' => $page->currentPage(), 'perPage' => $page->perPage(), 'total' => $page->total(), 'lastPage' => $page->lastPage()],
            'totals' => [
                'count' => (int) ($sums->n ?? 0),
                'balance' => (int) ($sums->balance ?? 0),
                'credit_limit' => (int) ($sums->credit_limit ?? 0),
            ],
            'groups' => $this->groups(),
            'districts' => $this->geo->children(GeoCatalog::DISTRICTS, null),
            'can' => $this->abilities($request),
        ];
    }

    /** @return array<string, mixed> */
    private function showProps(Request $request, Customer $model): array
    {
        $user = $this->actor($request);
        $model->load(['customerGroup', 'membershipCards']);
        $card = $model->membershipCards->sortByDesc('issue_no')->first();

        return [
            'customer' => $this->detail($model),
            'card' => $card instanceof MembershipCard ? $this->cardData($card, $model) : null,
            'brand' => app(CardBrand::class)->toArray(),
            'kpis' => $this->kpis($model, $user),
            'sales' => $user->can('viewAny', Sale::class) ? $this->sales($model) : [],
            'returns' => $user->can('viewAny', SaleReturn::class) ? $this->returns($model) : [],
            'payments' => $user->can('viewAny', Payment::class) ? $this->payments($model) : [],
            'messages' => $user->can('viewAny', Message::class) ? $this->messages($model) : [],
            'groups' => $this->groups(),
            'can' => [...$this->abilities($request), 'sms' => $user->can('send', Message::class)],
            'messaging' => fn (): array => app(MessagingUi::class)->props($user),
        ];
    }

    /**
     * Profile KPI tiles, two cheap aggregates: completed sales (count = visits, sum = lifetime spend) less
     * returns, over the current branch (all branches with `reports.all_branches`, like the statement);
     * the due and credit figures are the customer's tenant-wide balance and effective limit.
     *
     * @return array{lifetime_spend: int|null, visits: int|null, last_visit_at: string|null, returns: int|null, due: int, credit_limit: int|null, credit_used: int, credit_available: int|null}
     */
    private function kpis(Customer $c, User $user): array
    {
        $spend = $visits = $returns = null;
        $last = null;
        if ($user->can('viewAny', Sale::class)) {
            $sales = Sale::query()->where('customer_id', $c->id)->where('status', SaleStatus::Completed);
            $back = SaleReturn::query()->where('customer_id', $c->id);
            if ($user->can('reports.all_branches')) {
                $sales->withoutGlobalScope(BranchScope::class);
                $back->withoutGlobalScope(BranchScope::class);
            }
            $agg = $sales->toBase()->selectRaw('COUNT(*) as n, COALESCE(SUM(total), 0) as total, MAX(sold_at) as last')->first();
            $visits = (int) ($agg->n ?? 0);
            $returns = (int) $back->sum('total');
            $spend = (int) ($agg->total ?? 0) - $returns;
            $lastAt = $agg?->last;
            $last = is_string($lastAt) ? CarbonImmutable::parse($lastAt)->toIso8601String() : null;
        }
        $limit = app(CustomerAccounts::class)->creditLimit($c);
        $used = max(0, $c->balance->paisa);

        return [
            'lifetime_spend' => $spend,
            'visits' => $visits,
            'last_visit_at' => $last,
            'returns' => $returns,
            'due' => $c->balance->paisa,
            'credit_limit' => $limit?->paisa,
            'credit_used' => $used,
            'credit_available' => $limit instanceof Money ? max(0, $limit->paisa - $used) : null,
        ];
    }

    private function timezone(): string
    {
        return BusinessDay::timezone(app(TenantContext::class)->tenant());
    }

    private function defaultCreditLimit(): ?int
    {
        $default = app(TenantContext::class)->tenant()->setting('default_credit_limit');

        return is_int($default) ? $default : null;
    }

    /** Tenant-scoped lookup: another tenant's customer is a 404. */
    private function find(string $uuid): Customer
    {
        return Customer::query()->where('uuid', $uuid)->firstOrFail();
    }

    /** @param Builder<Customer> $query */
    private function applySearch(Builder $query, string $term): void
    {
        $query->search($term);
    }

    /** @return array<int, array{id: int, name: string, discount_bp: int}> */
    private function groups(): array
    {
        return CustomerGroup::query()->where('is_active', true)->orderBy('name')->get()
            ->map(static fn (CustomerGroup $g): array => ['id' => $g->id, 'name' => $g->name, 'discount_bp' => (int) $g->discount_bp])->values()->all();
    }

    /** @return array<string, bool> */
    private function abilities(Request $request): array
    {
        $user = $this->actor($request);

        return [
            'create' => $user->can('create', Customer::class),
            'update' => $user->can('customers.update'),
            'delete' => $user->can('customers.delete'),
            'sell' => $user->can('create', Sale::class),
            'collect' => $user->can('payments.collect'),
            'cards' => $user->can('viewAny', MembershipCard::class),
            'issue_card' => $user->can('create', MembershipCard::class),
            'reset_pin' => $user->can('customers.reset_pin'),
        ];
    }

    /** @return array<string, mixed> */
    private function row(Customer $c): array
    {
        $card = $c->membershipCards->sortByDesc('issue_no')->first();

        return [
            ...$this->brief($c),
            'group' => $c->customerGroup?->name,
            'balance' => $c->balance->paisa,
            // Effective limit: the customer's own, else the tenant default (what the register enforces).
            'credit_limit' => $c->credit_limit instanceof Money ? $c->credit_limit->paisa : $this->defaultCreditLimit(),
            'area' => $c->area,
            'address_line' => $c->address_line,
            'post_code' => $c->post_code,
            'location' => $this->location($c),
            'last_purchase_at' => $c->last_purchase_at?->toIso8601String(),
            'card_status' => $card instanceof MembershipCard ? $card->status->value : null,
            'pin_status' => CustomerPin::status($c),
            'is_active' => $c->is_active,
        ];
    }

    /**
     * The customer's structured location as display names (division → district → upazila → union).
     *
     * @return array{division: array{id: int, name_en: string, name_bn: string}|null, district: array{id: int, name_en: string, name_bn: string}|null, upazila: array{id: int, name_en: string, name_bn: string}|null, union: array{id: int, name_en: string, name_bn: string}|null}
     */
    private function location(Customer $c): array
    {
        return $this->geo->chainFor($c->geo_division_id, $c->geo_district_id, $c->geo_upazila_id, $c->geo_union_id);
    }

    /** @return array<string, mixed> */
    private function brief(Customer $c): array
    {
        return [
            'id' => $c->uuid,
            'uuid' => $c->uuid,
            'customer_no' => $c->customer_no === null ? null : CustomerNumber::format((string) $c->customer_no),
            'name' => $c->name,
            'name_bn' => $c->name_bn,
            'shop_name' => $c->shop_name,
            'phone' => $c->phone,
        ];
    }

    /**
     * Everything the profile and the edit form need; never the PIN.
     *
     * @return array<string, mixed>
     */
    private function detail(Customer $c): array
    {
        $c->loadMissing('customerGroup');

        return [
            ...$this->brief($c),
            'alt_phone' => $c->alt_phone,
            'email' => $c->email,
            'customer_group_id' => $c->customer_group_id,
            'group' => $c->customerGroup?->name,
            'group_discount_bp' => $c->customerGroup?->is_active === true ? (int) $c->customerGroup->discount_bp : 0,
            'credit_limit' => $c->credit_limit?->paisa,
            'balance' => $c->balance->paisa,
            'address_line' => $c->address_line,
            'area' => $c->area,
            'post_code' => $c->post_code,
            'geo_division_id' => $c->geo_division_id,
            'geo_district_id' => $c->geo_district_id,
            'geo_upazila_id' => $c->geo_upazila_id,
            'geo_union_id' => $c->geo_union_id,
            'location' => $this->location($c),
            'location_verified' => $c->location_verified,
            'dob' => $c->dob?->toDateString(),
            'anniversary_date' => $c->anniversary_date?->toDateString(),
            'notes' => $c->notes,
            'member_since' => $c->member_since?->toDateString(),
            'last_purchase_at' => $c->last_purchase_at?->toIso8601String(),
            'is_active' => $c->is_active,
            'is_walk_in' => $c->is_walk_in,
            'pin_status' => CustomerPin::status($c),
            'pin_set_at' => $c->pin_set_at?->toIso8601String(),
            'pin_locked_until' => CustomerPin::isLocked($c) ? $c->pin_locked_until?->toIso8601String() : null,
        ];
    }

    /** @return array<string, mixed> */
    private function cardData(MembershipCard $card, Customer $customer): array
    {
        return [
            'id' => $card->id,
            'card_code' => $card->card_code,
            'status' => $card->status->value,
            'issue_no' => $card->issue_no,
            'issued_at' => BusinessDay::localDate($card->issued_at, $this->timezone()),
            'print_count' => $card->print_count,
            'member_since' => $customer->member_since?->toDateString(),
        ];
    }

    /** @return array<int, array<string, mixed>> */
    private function sales(Customer $c): array
    {
        return Sale::query()->where('customer_id', $c->id)->latest('sold_at')->limit(self::TAB_LIMIT)->get()
            ->map(static fn (Sale $s): array => [
                'id' => $s->id,
                'number' => $s->number,
                'sold_at' => $s->sold_at->toIso8601String(),
                'status' => $s->status->value,
                'payment_status' => $s->payment_status->value,
                'total' => $s->total->paisa,
                'paid' => $s->paid->paisa,
                'due' => $s->due->paisa,
            ])->values()->all();
    }

    /** @return array<int, array<string, mixed>> */
    private function returns(Customer $c): array
    {
        return SaleReturn::query()->where('customer_id', $c->id)->latest('returned_at')->limit(self::TAB_LIMIT)->get()
            ->map(static fn (SaleReturn $r): array => [
                'id' => $r->id,
                'number' => $r->number,
                'returned_at' => $r->returned_at->toIso8601String(),
                'refund_mode' => $r->refund_mode->value,
                'total' => $r->total->paisa,
            ])->values()->all();
    }

    /** @return array<int, array<string, mixed>> */
    private function payments(Customer $c): array
    {
        return Payment::query()->where('party_type', PartyType::Customer)->where('party_id', $c->id)
            ->with(['paymentMethod', 'allocations'])->latest('paid_at')->limit(self::TAB_LIMIT)->get()
            ->map(static fn (Payment $p): array => [
                'id' => $p->id,
                'number' => $p->number,
                'paid_at' => $p->paid_at->toIso8601String(),
                'direction' => $p->direction->value,
                'method' => $p->paymentMethod->name ?? null,
                'amount' => $p->amount->paisa,
                'allocated' => $p->allocations->reduce(static fn (int $sum, $a): int => $sum + $a->amount->paisa, 0),
                'voided' => $p->voided_at !== null,
            ])->values()->all();
    }

    /** @return array<int, array<string, mixed>> */
    private function messages(Customer $c): array
    {
        return Message::query()->where('customer_id', $c->id)->latest('id')->limit(self::TAB_LIMIT)->get()
            ->map(static fn (Message $m): array => [
                'id' => $m->id,
                'event' => $m->event,
                'body' => $m->body,
                'status' => $m->status->value,
                'created_at' => $m->created_at?->toIso8601String(),
            ])->values()->all();
    }

    private function duplicate(Request $request, DuplicateCustomerPhone $e): RedirectResponse|JsonResponse
    {
        $message = __('This phone number already belongs to :name.', ['name' => $e->existing->name]);
        if ($request->expectsJson()) {
            return response()->json([
                'message' => $message,
                'errors' => ['phone' => [$message]],
                'existing' => $this->brief($e->existing),
            ], 422);
        }

        return back()->withInput()->withErrors(['phone' => $message]);
    }

    private function invalid(Request $request, InvalidPartyData $e): RedirectResponse|JsonResponse
    {
        if ($request->expectsJson()) {
            return response()->json(['message' => $e->getMessage(), 'errors' => ['form' => [$e->getMessage()]]], 422);
        }

        return back()->withInput()->withErrors(['form' => $e->getMessage()]);
    }
}
