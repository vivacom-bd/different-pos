<?php

declare(strict_types=1);

namespace App\Actions\Customers;

use App\Enums\Gender;
use App\Exceptions\Domain\DuplicateCustomerPhone;
use App\Exceptions\Domain\InvalidPartyData;
use App\Models\Customer;
use App\Models\CustomerGroup;
use App\Models\User;
use App\Support\Audit\Audit;
use App\Support\Catalog\Values;
use App\Support\Customers\CustomerNumber;
use App\Support\Database\Transaction;
use App\Support\Geo\GeoCatalog;
use App\Support\Money\Money;
use App\Support\Phone\BdPhone;
use DateTimeInterface;
use Illuminate\Support\Carbon;

/**
 * Creates or updates a customer. The phone is normalised to E.164 (+880…) and must be unique in the
 * tenant: a clash throws DuplicateCustomerPhone carrying the existing customer. On create the
 * customer_no is allocated from the tenant sequence (server-assigned, Luhn-checked, never reused).
 * The walk-in customer cannot be edited. A location (geo ids) is checked to be one chain and its
 * parents are filled from the deepest child; a location saved by a user is `location_verified`.
 */
final readonly class SaveCustomer
{
    public function __construct(private CustomerNumber $numbers, private GeoCatalog $geo) {}

    /**
     * @param  array{name?: string, name_bn?: string|null, shop_name?: string|null, phone?: string, alt_phone?: string|null, email?: string|null, gender?: Gender|string|null, dob?: DateTimeInterface|string|null, anniversary_date?: DateTimeInterface|string|null, customer_group_id?: int|null, credit_limit?: Money|string|null, vat_bin?: string|null, geo_division_id?: int|null, geo_district_id?: int|null, geo_upazila_id?: int|null, geo_union_id?: int|null, address_line?: string|null, area?: string|null, post_code?: string|null, notes?: string|null, origin_client_uuid?: string|null}  $data
     */
    public function handle(User $actor, array $data, ?Customer $customer = null): Customer
    {
        return Transaction::run(function () use ($actor, $data, $customer): Customer {
            $customer = $customer instanceof Customer ? Customer::query()->whereKey($customer->id)->lockForUpdate()->firstOrFail() : null;
            $created = ! $customer instanceof Customer;
            if (! $created && $customer->is_walk_in) {
                throw InvalidPartyData::invalid('The walk-in customer cannot be edited.');
            }
            $customer ??= new Customer;

            $name = trim((string) ($data['name'] ?? ($created ? '' : $customer->name)));
            if ($name === '') {
                throw InvalidPartyData::required('Name');
            }

            $attributes = ['name' => $name];

            if ($created || array_key_exists('phone', $data)) {
                $raw = (string) ($data['phone'] ?? '');
                $phone = BdPhone::toE164($raw);
                if (trim($raw) === '') {
                    throw InvalidPartyData::required('Phone');
                }
                if ($phone === null) {
                    throw InvalidPartyData::invalid('Enter a valid Bangladeshi mobile number.');
                }
                $existing = Customer::query()->where('phone', $phone)->when(! $created, fn ($q) => $q->whereKeyNot($customer->id))->first();
                if ($existing instanceof Customer) {
                    throw new DuplicateCustomerPhone($existing, $phone);
                }
                $attributes['phone'] = $phone;
            }

            if (array_key_exists('alt_phone', $data)) {
                $alt = trim((string) $data['alt_phone']);
                $attributes['alt_phone'] = $alt === '' ? null : (BdPhone::toE164($alt) ?? throw InvalidPartyData::invalid('Enter a valid alternate mobile number.'));
            }

            foreach (['name_bn', 'shop_name', 'email', 'address_line', 'area', 'post_code', 'notes', 'origin_client_uuid'] as $key) {
                if (array_key_exists($key, $data)) {
                    $value = is_string($data[$key]) ? trim($data[$key]) : $data[$key];
                    $attributes[$key] = $value === '' ? null : $value;
                }
            }
            if (isset($attributes['email']) && filter_var($attributes['email'], FILTER_VALIDATE_EMAIL) === false) {
                throw InvalidPartyData::invalid('Enter a valid email address.');
            }
            if (isset($attributes['post_code']) && preg_match('/^[0-9]{4}$/', (string) $attributes['post_code']) !== 1) {
                throw InvalidPartyData::invalid('A post code has 4 digits.');
            }

            if (array_key_exists('vat_bin', $data)) {
                $bin = trim((string) $data['vat_bin']);
                if ($bin !== '' && preg_match('/^[0-9]{9,13}$/', $bin) !== 1) {
                    throw InvalidPartyData::invalid('A VAT BIN has 9 to 13 digits.');
                }
                $attributes['vat_bin'] = $bin === '' ? null : $bin;
            }

            if (array_key_exists('gender', $data)) {
                $gender = $data['gender'];
                $attributes['gender'] = $gender === null || $gender === '' ? null
                    : ($gender instanceof Gender ? $gender : (Gender::tryFrom($gender) ?? throw InvalidPartyData::invalid('Unknown gender.')));
            }
            foreach (['dob', 'anniversary_date'] as $key) {
                if (array_key_exists($key, $data)) {
                    $attributes[$key] = $data[$key] === null || $data[$key] === '' ? null : $data[$key];
                }
            }

            $geoKeys = ['geo_division_id', 'geo_district_id', 'geo_upazila_id', 'geo_union_id'];
            if (array_intersect_key($data, array_flip($geoKeys)) !== []) {
                $ids = [];
                foreach ($geoKeys as $key) {
                    // Any given level replaces the whole location: absent levels are cleared, parents re-derived below.
                    $ids[$key] = isset($data[$key]) ? (int) $data[$key] : null;
                }
                $chain = $this->geo->resolve($ids['geo_division_id'], $ids['geo_district_id'], $ids['geo_upazila_id'], $ids['geo_union_id']);
                if ($chain === null) {
                    throw InvalidPartyData::invalid('The chosen district, upazila and union do not belong together.');
                }
                $attributes = [...$attributes, ...$chain, 'location_verified' => true];
            }

            if (array_key_exists('customer_group_id', $data)) {
                if ($data['customer_group_id'] !== null) {
                    CustomerGroup::query()->findOrFail($data['customer_group_id']);
                }
                $attributes['customer_group_id'] = $data['customer_group_id'];
            }
            if (array_key_exists('credit_limit', $data)) {
                $limit = Values::moneyOrNull($data['credit_limit']);
                if ($limit instanceof Money && $limit->isNegative()) {
                    throw InvalidPartyData::invalid('The credit limit must not be negative.');
                }
                $attributes['credit_limit'] = $limit;
            }

            $customer->fill($attributes);
            if (! $customer->exists) {
                $customer->customer_no = (int) $this->numbers->allocate();
                $customer->member_since = Carbon::now();
                $customer->created_by = $actor->id;
            }
            $customer->updated_by = $actor->id;
            $customer->save();
            $customer->refresh();

            Audit::log($created ? 'customer.created' : 'customer.updated', $customer, [], $actor);

            return $customer;
        });
    }
}
