<?php

declare(strict_types=1);

use App\Actions\Customers\DeactivateCustomer;
use App\Actions\Customers\MergeCustomers;
use App\Actions\Customers\SaveCustomer;
use App\Enums\CardStatus;
use App\Exceptions\Domain\DuplicateCustomerPhone;
use App\Exceptions\Domain\InvalidPartyData;
use App\Models\Customer;
use App\Models\CustomerGroup;
use App\Models\MembershipCard;
use App\Models\Payment;
use App\Models\Sale;
use App\Models\Tenant;
use App\Support\Customers\CustomerNumber;
use App\Support\Customers\CustomerSearch;
use App\Support\Money\Money;
use App\Support\Numbers\Digits;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Tests\Fixtures\StockWorld;

beforeEach(function (): void {
    $this->world = StockWorld::make();
    $this->user = $this->world->user;
    $this->save = fn (array $data = [], ?Customer $customer = null): Customer => app(SaveCustomer::class)->handle($this->user, [
        'name' => 'Rahim Uddin', 'phone' => '01712345678', ...$data,
    ], $customer);
});

afterEach(function (): void {
    app(TenantContext::class)->forget();
});

it('creates a customer with a normalised phone and a Luhn customer number', function (): void {
    $customer = ($this->save)([
        'shop_name' => 'Rahim Store', 'name_bn' => 'রহিম', 'credit_limit' => '50000', 'address_line' => 'Mirpur 10',
        'dob' => '1990-05-01', 'anniversary_date' => '2015-02-14', 'gender' => 'male', 'vat_bin' => '123456789',
    ]);
    $second = ($this->save)(['phone' => '+880 1812-345678', 'name' => 'Karim']);

    expect($customer->phone)->toBe('+8801712345678')
        ->and(CustomerNumber::isValid((string) $customer->customer_no))->toBeTrue()
        ->and((string) $customer->customer_no)->toStartWith('1000001')
        ->and((string) $second->customer_no)->toStartWith('1000002')
        ->and($customer->credit_limit?->paisa)->toBe(5_000_000)
        ->and($customer->shop_name)->toBe('Rahim Store')
        ->and($customer->address_line)->toBe('Mirpur 10')
        ->and($customer->dob?->toDateString())->toBe('1990-05-01')
        ->and($customer->member_since?->toDateString())->toBe(now()->toDateString())
        ->and($customer->created_by)->toBe($this->user->id);
});

it('accepts Bangla digits in the phone', function (): void {
    expect(($this->save)(['phone' => '০১৭১২৩৪৫৬৭৮'])->phone)->toBe('+8801712345678');
});

it('throws DuplicateCustomerPhone carrying the existing customer', function (): void {
    $first = ($this->save)();

    try {
        ($this->save)(['name' => 'Someone else', 'phone' => '+8801712345678']);
        $this->fail('Expected a duplicate phone.');
    } catch (DuplicateCustomerPhone $e) {
        expect($e->existing->id)->toBe($first->id)->and($e->phone)->toBe('+8801712345678');
    }
    expect(Customer::query()->count())->toBe(1);
});

it('allows the customer to keep its own phone on update and never changes the customer number', function (): void {
    $customer = ($this->save)();
    $no = $customer->customer_no;
    $updated = ($this->save)(['name' => 'Rahim U.', 'shop_name' => 'New shop'], $customer);
    $same = ($this->save)(['phone' => '01712345678'], $customer);

    expect($updated->customer_no)->toBe($no)->and($same->customer_no)->toBe($no)->and($updated->shop_name)->toBe('New shop');

    $other = ($this->save)(['phone' => '01912345678', 'name' => 'Other']);
    expect(fn () => ($this->save)(['phone' => '01712345678'], $other))->toThrow(DuplicateCustomerPhone::class);
});

it('validates input', function (array $data, string $message): void {
    ($this->save)($data);
})->with([
    'blank name' => [['name' => ' '], 'Name'],
    'no phone' => [['phone' => ''], 'Phone'],
    'bad phone' => [['phone' => '12345'], 'valid'],
    'bad alt phone' => [['alt_phone' => '999'], 'alternate'],
    'bad email' => [['email' => 'nope'], 'email'],
    'bad gender' => [['gender' => 'robot'], 'gender'],
    'bad post code' => [['post_code' => '12'], 'post code'],
    'bad BIN' => [['vat_bin' => 'ABC'], 'BIN'],
    'negative credit' => [['credit_limit' => '-5'], 'credit'],
])->throws(InvalidPartyData::class);

it('protects the walk-in customer', function (): void {
    $walkIn = app(TenantContext::class)->withoutTenancy(fn (): Customer => Customer::factory()->create(['tenant_id' => $this->world->tenant->id, 'is_walk_in' => true]));

    expect(fn () => ($this->save)(['name' => 'X'], $walkIn))->toThrow(InvalidPartyData::class);
    expect(fn () => app(DeactivateCustomer::class)->handle($this->user, $walkIn))->toThrow(InvalidPartyData::class);
});

it('deactivates a customer idempotently', function (): void {
    $customer = ($this->save)();
    app(DeactivateCustomer::class)->handle($this->user, $customer);
    $again = app(DeactivateCustomer::class)->handle($this->user, $customer);

    expect($again->is_active)->toBeFalse()->and(Customer::query()->find($customer->id)?->is_active)->toBeFalse();
});

it('merges a duplicate into the survivor: remaps history, sums balances, soft-deletes the loser', function (): void {
    $survivor = ($this->save)();
    $loser = ($this->save)(['phone' => '01912345678', 'name' => 'Rahim (dup)']);
    app(TenantContext::class)->withoutTenancy(function () use ($survivor, $loser): void {
        $survivor->forceFill(['balance' => Money::ofPaisa(10000)])->save();
        $loser->forceFill(['balance' => Money::ofPaisa(2500), 'last_purchase_at' => now()])->save();
    });
    $sale = app(TenantContext::class)->withoutTenancy(fn (): Sale => Sale::factory()->create(['tenant_id' => $this->world->tenant->id, 'branch_id' => $this->world->branch->id, 'customer_id' => $loser->id]));
    $payment = app(TenantContext::class)->withoutTenancy(fn (): Payment => Payment::factory()->create(['tenant_id' => $this->world->tenant->id, 'branch_id' => $this->world->branch->id, 'party_type' => 'customer', 'party_id' => $loser->id]));
    $card = app(TenantContext::class)->withoutTenancy(fn (): MembershipCard => MembershipCard::factory()->create(['tenant_id' => $this->world->tenant->id, 'customer_id' => $loser->id, 'issued_by' => $this->user->id]));

    $merged = app(MergeCustomers::class)->handle($this->user, $survivor, $loser);

    expect($merged->id)->toBe($survivor->id)
        ->and($merged->customer_no)->toBe($survivor->customer_no)
        ->and($merged->balance->paisa)->toBe(12500)
        ->and($merged->last_purchase_at)->not->toBeNull();

    app(TenantContext::class)->withoutTenancy(function () use ($loser, $survivor, $sale, $payment, $card): void {
        $shell = Customer::withTrashed()->findOrFail($loser->id);
        expect($shell->trashed())->toBeTrue()
            ->and($shell->merged_into_id)->toBe($survivor->id)
            ->and($shell->phone)->toBeNull()
            ->and($shell->balance->paisa)->toBe(0)
            ->and($shell->customer_no)->toBe($loser->customer_no)
            ->and(Sale::query()->findOrFail($sale->id)->customer_id)->toBe($survivor->id)
            ->and(Payment::query()->findOrFail($payment->id)->party_id)->toBe($survivor->id)
            ->and(MembershipCard::query()->findOrFail($card->id)->status)->toBe(CardStatus::Revoked);
    });

    // The freed phone can be used again.
    expect(($this->save)(['phone' => '01912345678', 'name' => 'New owner'])->id)->not->toBe($loser->id);
});

it('refuses a self merge, a repeated merge and the walk-in', function (): void {
    $a = ($this->save)();
    $b = ($this->save)(['phone' => '01912345678']);
    $walkIn = app(TenantContext::class)->withoutTenancy(fn (): Customer => Customer::factory()->create(['tenant_id' => $this->world->tenant->id, 'is_walk_in' => true]));

    expect(fn () => app(MergeCustomers::class)->handle($this->user, $a, $a))->toThrow(InvalidPartyData::class);
    expect(fn () => app(MergeCustomers::class)->handle($this->user, $a, $walkIn))->toThrow(InvalidPartyData::class);
    app(MergeCustomers::class)->handle($this->user, $a, $b);
    expect(fn () => app(MergeCustomers::class)->handle($this->user, $a, $b))->toThrow(InvalidPartyData::class);
});

it('searches by number (Bangla digits and spaces), name, shop and phone suffix', function (): void {
    $rahim = ($this->save)(['shop_name' => 'Bismillah Traders', 'name_bn' => 'রহিম উদ্দিন']);
    ($this->save)(['name' => 'Karim', 'phone' => '01912345679']);
    $search = app(CustomerSearch::class);
    $no = (string) $rahim->customer_no;
    $formatted = CustomerNumber::format($no);

    expect($search->search($no)->pluck('id')->all())->toBe([$rahim->id])
        ->and($search->search($formatted)->pluck('id')->all())->toBe([$rahim->id])
        ->and($search->search(Digits::toBangla($no))->pluck('id')->all())->toBe([$rahim->id])
        ->and($search->search($formatted)->first()?->customerNo)->toBe($formatted)
        ->and($search->search('rahi')->pluck('id')->all())->toBe([$rahim->id])
        ->and($search->search('রহিম')->pluck('id')->all())->toBe([$rahim->id])
        ->and($search->search('bismillah')->pluck('id')->all())->toBe([$rahim->id])
        ->and($search->search('12345678')->pluck('id')->all())->toBe([$rahim->id])
        ->and($search->search('01712345678')->pluck('id')->all())->toBe([$rahim->id])
        ->and($search->search('')->all())->toBe([])
        ->and($search->search('zzz')->all())->toBe([]);
});

it('limits search results to 20 and treats LIKE wildcards literally', function (): void {
    foreach (range(1, 25) as $i) {
        ($this->save)(['name' => 'Bulk '.$i, 'phone' => '0171000'.str_pad((string) $i, 4, '0', STR_PAD_LEFT)]);
    }

    expect(app(CustomerSearch::class)->search('Bulk', 50))->toHaveCount(20)
        ->and(app(CustomerSearch::class)->search('%'))->toHaveCount(0);
});

it('isolates customers per tenant', function (): void {
    $foreign = app(TenantContext::class)->withoutTenancy(function (): Customer {
        $tenant = Tenant::factory()->create();
        $group = CustomerGroup::factory()->create(['tenant_id' => $tenant->id]);

        return Customer::factory()->create(['tenant_id' => $tenant->id, 'phone' => '+8801712345678', 'name' => 'Rahim', 'customer_group_id' => $group->id]);
    });

    $mine = ($this->save)(); // same phone is free here: no duplicate across tenants
    expect($mine->id)->not->toBe($foreign->id)
        ->and(app(CustomerSearch::class)->search('Rahim')->pluck('id')->all())->toBe([$mine->id]);
    expect(fn () => ($this->save)(['name' => 'Hijack'], $foreign))->toThrow(ModelNotFoundException::class);
    expect(fn () => app(DeactivateCustomer::class)->handle($this->user, $foreign))->toThrow(ModelNotFoundException::class);
    expect(fn () => app(MergeCustomers::class)->handle($this->user, $mine, $foreign))->toThrow(InvalidPartyData::class);
    expect(fn () => ($this->save)(['phone' => '01812345678', 'customer_group_id' => $foreign->customer_group_id]))->toThrow(ModelNotFoundException::class);
});
