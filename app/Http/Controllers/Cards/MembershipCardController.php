<?php

declare(strict_types=1);

namespace App\Http\Controllers\Cards;

use App\Actions\Cards\EnsureActiveCard;
use App\Actions\Cards\IssueCard;
use App\Actions\Cards\RecordCardPrint;
use App\Actions\Cards\ReissueCard;
use App\Actions\Cards\ReportCardLost;
use App\Enums\CardPrintLayout;
use App\Enums\CardStatus;
use App\Exceptions\Domain\InvalidPartyData;
use App\Http\Controllers\Controller;
use App\Http\Requests\Cards\CardIdsRequest;
use App\Http\Requests\Cards\IssueCardsRequest;
use App\Models\Customer;
use App\Models\CustomerGroup;
use App\Models\MembershipCard;
use App\Models\User;
use App\Support\Cards\CardBrand;
use App\Support\Cards\CardExport;
use App\Support\Cards\CardRenderer;
use App\Support\Customers\CustomerNumber;
use App\Support\Http\TableQuery;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;

/**
 * Membership card studio: every customer is a member (the card number is the customer number), so the
 * index lists members with their card; issue, lost/reissue, and the press hand-off (print-ready HTML
 * for the browser's print dialog: CR80, bleed with crop marks, A4 10-up; variable-data CSV; print
 * recording). Only active cards are printed or exported; `cards.prepare` (POST) issues a missing card first.
 */
final class MembershipCardController extends Controller
{
    /** Members per studio page: four A4 10-up sheets. */
    public const int PER_PAGE = 40;

    public function __construct(private readonly EnsureActiveCard $ensure, private readonly TenantContext $context) {}

    public function index(Request $request): InertiaResponse
    {
        Gate::authorize('viewAny', MembershipCard::class);
        $table = TableQuery::fromRequest($request, ['name', 'customer_no', 'member_since'], ['group', 'status'], 'name');

        $query = $this->members()->with(['customerGroup', 'membershipCards']);
        if (isset($table->filters['group'])) {
            $query->where('customer_group_id', (int) $table->filters['group']);
        }
        $this->applyStatus($query, $table->filters['status'] ?? '');
        if ($table->search !== '') {
            $this->applySearch($query, $table->search);
        }
        $page = $table->applySort($query)->orderBy('id')->paginate(self::PER_PAGE, ['*'], 'page', $table->page);
        /** @var list<Customer> $items */
        $items = $page->items();
        $user = $this->actor($request);

        return Inertia::render('cards/index', [
            'members' => array_map($this->memberRow(...), $items),
            'meta' => ['page' => $page->currentPage(), 'perPage' => $page->perPage(), 'total' => $page->total(), 'lastPage' => $page->lastPage()],
            'totals' => $this->totals(),
            'groups' => $this->groups(),
            'brand' => (new CardBrand($this->context))->toArray(),
            'can' => [
                'issue' => $user->can('create', MembershipCard::class),
                'customers' => $user->can('viewAny', Customer::class),
            ],
        ]);
    }

    public function issue(IssueCardsRequest $request, IssueCard $issue): RedirectResponse
    {
        Gate::authorize('create', MembershipCard::class);
        $user = $this->actor($request);

        /** @var list<string> $uuids */
        $uuids = $request->validated('customers');
        $issued = 0;
        $errors = [];
        foreach (Customer::query()->whereIn('uuid', $uuids)->get() as $customer) {
            try {
                $issue->handle($user, $customer);
                $issued++;
            } catch (InvalidPartyData $e) {
                $errors[] = $customer->name.': '.$e->getMessage();
            }
        }
        if ($issued > 0) {
            Inertia::flash('toast', ['type' => 'success', 'message' => trans_choice('{1} :count card issued.|[2,*] :count cards issued.', $issued, ['count' => $issued])]);
        }

        return $errors === [] ? back() : back()->withErrors(['form' => implode(' ', $errors)]);
    }

    /**
     * POST: issues what is missing (a customer without an active card gets one now) and answers with the
     * ids of the printable cards; the GET print/export endpoints then take those ids and never write.
     */
    public function prepare(CardIdsRequest $request): JsonResponse
    {
        Gate::authorize('create', MembershipCard::class);

        return response()->json(['card_ids' => $this->resolve($request)->pluck('id')->map(intval(...))->values()->all()]);
    }

    /** GET: print-ready HTML (front and back pages with @page); the browser's print dialog saves the PDF. */
    public function print(CardIdsRequest $request, CardRenderer $renderer): Response
    {
        Gate::authorize('create', MembershipCard::class);
        $cards = $this->printable($request);
        $html = $request->a4()
            ? $renderer->a4($cards, ! $request->has('crop') || $request->cropMarks())
            : $renderer->html($cards, $request->bleed(), $request->cropMarks());

        return response($html, 200, ['Content-Type' => 'text/html; charset=UTF-8']);
    }

    /** GET: the press data file, one row per card. */
    public function export(CardIdsRequest $request): Response
    {
        Gate::authorize('create', MembershipCard::class);
        $csv = CardExport::csv($this->printable($request));

        return response($csv, 200, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="membership-cards-'.now()->format('Ymd-His').'.csv"',
        ]);
    }

    /** POST: the cards were sent to print; bumps print_count (audited). */
    public function printed(CardIdsRequest $request, RecordCardPrint $record): RedirectResponse
    {
        Gate::authorize('create', MembershipCard::class);
        $user = $this->actor($request);
        $layout = match ($request->layout()) {
            'bleed' => CardPrintLayout::Pdf,
            'a4' => CardPrintLayout::A410,
            default => CardPrintLayout::Cr80,
        };
        foreach ($this->printable($request) as $card) {
            $record->handle($user, $card, $layout);
        }

        return back();
    }

    public function lost(Request $request, int $card, ReportCardLost $lost): RedirectResponse
    {
        $model = MembershipCard::query()->findOrFail($card);
        Gate::authorize('block', $model);
        $reason = $request->validate(['reason' => ['nullable', 'string', 'max:255']])['reason'] ?? null;

        try {
            $lost->handle($this->actor($request), $model, $reason);
        } catch (InvalidPartyData $e) {
            return back()->withErrors(['form' => $e->getMessage()]);
        }
        Inertia::flash('toast', ['type' => 'success', 'message' => __('Card marked lost.')]);

        return back();
    }

    public function reissue(Request $request, int $card, ReissueCard $reissue): RedirectResponse
    {
        $model = MembershipCard::query()->findOrFail($card);
        Gate::authorize('reissue', $model);
        $reason = $request->validate(['reason' => ['nullable', 'string', 'max:255']])['reason'] ?? null;

        try {
            $reissue->handle($this->actor($request), $model, $reason);
        } catch (InvalidPartyData $e) {
            return back()->withErrors(['form' => $e->getMessage()]);
        }
        Inertia::flash('toast', ['type' => 'success', 'message' => __('Card reissued.')]);

        return back();
    }

    private function actor(Request $request): User
    {
        $user = $request->user();
        abort_unless($user instanceof User, 401);

        return $user;
    }

    /**
     * Active cards for the requested card ids only; never issues (GET endpoints and the print record).
     *
     * @return Collection<int, MembershipCard>
     */
    private function printable(CardIdsRequest $request): Collection
    {
        // Read only: a customer's existing active card can be printed, but a GET never issues one.
        $ids = $request->cardIds();
        $uuids = $request->customerUuids();
        $cards = $ids === [] && $uuids === [] ? new Collection : MembershipCard::query()
            ->where('status', CardStatus::Active)
            ->where(fn ($q) => $q->whereIn('id', $ids)->orWhereHas('customer', fn ($c) => $c->whereIn('uuid', $uuids)))
            ->with('customer.customerGroup')->orderBy('id')->get();
        abort_if($cards->isEmpty(), 404);

        return $cards;
    }

    /**
     * Active cards for the requested card ids, then for the requested customers in request order
     * (a customer without an active card gets one now; one that cannot have one is skipped).
     *
     * @return Collection<int, MembershipCard>
     */
    private function resolve(CardIdsRequest $request): Collection
    {
        $cards = new Collection;
        if ($request->cardIds() !== []) {
            $cards = MembershipCard::query()->whereIn('id', $request->cardIds())->where('status', CardStatus::Active)
                ->with('customer.customerGroup')->orderBy('id')->get();
        }

        $uuids = $request->customerUuids();
        if ($uuids !== []) {
            $user = $this->actor($request);
            $customers = Customer::query()->whereIn('uuid', $uuids)->with('customerGroup')->get()->keyBy('uuid');
            $seen = $cards->pluck('id')->all();
            foreach ($uuids as $uuid) {
                $customer = $customers->get($uuid);
                if (! $customer instanceof Customer) {
                    continue;
                }
                try {
                    $card = $this->ensure->handle($user, $customer);
                } catch (InvalidPartyData) {
                    continue;
                }
                if (! in_array($card->id, $seen, true)) {
                    $seen[] = $card->id;
                    $cards->push($card->setRelation('customer', $customer));
                }
            }
        }
        abort_if($cards->isEmpty(), 404);

        return $cards;
    }

    /**
     * Every customer is a member: active, not the walk-in, not merged away, numbered.
     *
     * @return Builder<Customer>
     */
    private function members(): Builder
    {
        return Customer::query()->where('is_walk_in', false)->where('is_active', true)
            ->whereNull('merged_into_id')->whereNotNull('customer_no');
    }

    /** @param Builder<Customer> $query */
    private function applyStatus(Builder $query, string $status): void
    {
        $active = static fn (Builder $q): Builder => $q->where('status', CardStatus::Active);
        $lost = static fn (Builder $q): Builder => $q->where('status', CardStatus::Lost);
        match ($status) {
            'issued' => $query->whereHas('membershipCards', $active),
            'lost' => $query->whereDoesntHave('membershipCards', $active)->whereHas('membershipCards', $lost),
            'not_issued' => $query->whereDoesntHave('membershipCards', $active)->whereDoesntHave('membershipCards', $lost),
            default => null,
        };
    }

    /**
     * Name (EN/BN), shop, phone (national digits), customer number (prefix) or card code.
     *
     * @param  Builder<Customer>  $query
     */
    private function applySearch(Builder $query, string $term): void
    {
        $query->where(function (Builder $q) use ($term): void {
            $q->search($term);
            if (preg_match('/^MC\d{3,}$/i', trim($term)) === 1) {
                $q->orWhereHas('membershipCards', fn (Builder $c): Builder => $c->where('card_code', 'like', strtoupper(trim($term)).'%'));
            }
        });
    }

    /** @return array{members: int, issued: int, not_issued: int, lost: int} */
    private function totals(): array
    {
        $members = $this->members()->count();
        $issued = $this->members()->whereHas('membershipCards', static fn (Builder $q): Builder => $q->where('status', CardStatus::Active))->count();
        $lostQuery = $this->members();
        $this->applyStatus($lostQuery, 'lost');
        $lost = $lostQuery->count();

        return ['members' => $members, 'issued' => $issued, 'not_issued' => $members - $issued - $lost, 'lost' => $lost];
    }

    /**
     * Active groups with their member counts (the filter chips).
     *
     * @return array<int, array{id: int, name: string, count: int}>
     */
    private function groups(): array
    {
        /** @var array<int|string, int|string> $counts */
        $counts = $this->members()->whereNotNull('customer_group_id')->selectRaw('customer_group_id, count(*) as aggregate')
            ->groupBy('customer_group_id')->pluck('aggregate', 'customer_group_id')->all();

        return CustomerGroup::query()->where('is_active', true)->orderBy('name')->get()
            ->map(static fn (CustomerGroup $g): array => ['id' => $g->id, 'name' => $g->name, 'count' => (int) ($counts[$g->id] ?? 0)])
            ->values()->all();
    }

    /** @return array<string, mixed> */
    private function memberRow(Customer $c): array
    {
        $cards = $c->membershipCards;
        $card = $cards->firstWhere('status', CardStatus::Active) ?? $cards->sortByDesc('issue_no')->first();

        return [
            'uuid' => $c->uuid,
            'name' => $c->name,
            'name_bn' => $c->name_bn,
            'shop_name' => $c->shop_name,
            'phone' => $c->phone,
            'customer_no' => CustomerNumber::format((string) $c->customer_no),
            'member_since' => $c->member_since?->toDateString(),
            'group' => $c->customerGroup?->name,
            'card' => $card instanceof MembershipCard ? [
                'id' => $card->id,
                'code' => $card->card_code,
                'status' => $card->status->value,
                'issue_no' => $card->issue_no,
                'print_count' => $card->print_count,
                'printed_at' => $card->last_printed_at?->toIso8601String(),
            ] : null,
        ];
    }
}
