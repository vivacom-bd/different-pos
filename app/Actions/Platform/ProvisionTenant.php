<?php

declare(strict_types=1);

namespace App\Actions\Platform;

use App\Actions\Settings\EnsureSystemPaymentMethods;
use App\Actions\Tenancy\SeedTenantRoles;
use App\Enums\PaymentMethodType;
use App\Exceptions\Domain\InvalidAdminData;
use App\Models\Branch;
use App\Models\Courier;
use App\Models\Customer;
use App\Models\PaymentMethod;
use App\Models\Register;
use App\Models\Tenant;
use App\Models\Unit;
use App\Models\User;
use App\Notifications\TenantOwnerInvitation;
use App\Support\Audit\Audit;
use App\Support\Customers\CustomerNumber;
use App\Support\Database\Transaction;
use App\Support\Permissions\SystemRoles;
use App\Support\Phone\BdPhone;
use App\Support\Platform\ProvisionedTenant;
use App\Support\Tenancy\TenantContext;
use DateTimeZone;
use Illuminate\Support\Str;
use Spatie\Permission\PermissionRegistrar;

/**
 * Creates a business with everything a first sale needs: Main branch (MAIN), register R1, Cash + the system tenders
 * (store credit, exchange credit), a Piece unit, the five system roles, the walk-in customer (first customer number)
 * and the Owner user, who receives a temporary password by mail and must change it at first sign-in.
 * The slug is permanent and unique: a second call with the same slug fails instead of creating a duplicate.
 */
final readonly class ProvisionTenant
{
    public const array LOCALES = ['en', 'bn'];

    public function __construct(
        private TenantContext $context,
        private SeedTenantRoles $roles,
        private EnsureSystemPaymentMethods $tenders,
        private CustomerNumber $customerNumbers,
        private PermissionRegistrar $permissions,
    ) {}

    /**
     * @param  array{name?: string, slug?: string|null, owner_name?: string, owner_email?: string, owner_phone?: string|null, timezone?: string|null, locale?: string|null, phone?: string|null}  $data
     */
    public function handle(User $actor, array $data): ProvisionedTenant
    {
        $result = Transaction::run(function () use ($actor, $data): ProvisionedTenant {
            $name = trim((string) ($data['name'] ?? ''));
            if ($name === '') {
                throw InvalidAdminData::required('Business name');
            }
            $slug = Str::slug(trim((string) ($data['slug'] ?? '')) ?: $name);
            if ($slug === '') {
                throw InvalidAdminData::required('Slug');
            }
            if (Tenant::query()->where('slug', $slug)->exists()) {
                throw InvalidAdminData::duplicate('business slug', $slug);
            }

            $ownerName = trim((string) ($data['owner_name'] ?? ''));
            $ownerEmail = mb_strtolower(trim((string) ($data['owner_email'] ?? '')));
            if ($ownerName === '') {
                throw InvalidAdminData::required('Owner name');
            }
            if ($ownerEmail === '' || filter_var($ownerEmail, FILTER_VALIDATE_EMAIL) === false) {
                throw InvalidAdminData::invalid('The owner needs a valid email address for the invitation.');
            }
            if (User::query()->withTrashed()->where('email', $ownerEmail)->exists()) {
                throw InvalidAdminData::duplicate('user email', $ownerEmail);
            }
            $ownerPhone = $this->phone($data['owner_phone'] ?? null);
            $timezone = trim((string) ($data['timezone'] ?? '')) ?: 'Asia/Dhaka';
            if (! in_array($timezone, DateTimeZone::listIdentifiers(), true)) {
                throw InvalidAdminData::invalid('Unknown timezone.');
            }
            $locale = in_array($data['locale'] ?? null, self::LOCALES, true) ? (string) $data['locale'] : 'en';

            $tenant = Tenant::query()->create([
                'name' => $name,
                'slug' => $slug,
                'phone' => $this->phone($data['phone'] ?? null) ?? $ownerPhone,
                'email' => $ownerEmail,
                'currency' => 'BDT',
                'timezone' => $timezone,
                'locale' => $locale,
            ]);

            $password = Str::password(14, symbols: false);

            $branch = $this->context->run($tenant, null, static fn (): Branch => Branch::query()->create(['code' => 'MAIN', 'name' => 'Main Branch', 'is_active' => true]));

            return $this->context->run($tenant, $branch, function () use ($tenant, $branch, $actor, $ownerName, $ownerEmail, $ownerPhone, $password): ProvisionedTenant {
                $register = new Register;
                $register->forceFill(['tenant_id' => $tenant->id, 'branch_id' => $branch->id, 'code' => 'R1', 'name' => 'R1', 'number_year' => (int) now()->year, 'is_active' => true])->save();

                $this->tenders->handle($tenant);
                PaymentMethod::query()->create(['name' => 'Cash', 'type' => PaymentMethodType::Cash, 'requires_reference' => false, 'sort_order' => 1, 'is_active' => true]);
                Unit::query()->create(['name' => 'Piece', 'short_name' => 'pcs', 'allows_decimal' => false, 'is_active' => true]);
                foreach (['m' => 'Metre', 'yd' => 'Yard', 'kg' => 'Kilogram'] as $short => $name) {
                    Unit::query()->create(['name' => $name, 'short_name' => $short, 'allows_decimal' => true, 'is_active' => true]);
                }

                foreach (Courier::DEFAULT_NAMES as $courierName) {
                    Courier::query()->create(['name' => $courierName, 'is_default' => false, 'is_active' => true]);
                }

                $this->roles->handle($tenant);

                $walkIn = new Customer;
                $walkIn->forceFill(['name' => 'Walk-in customer', 'is_walk_in' => true, 'is_active' => true, 'customer_no' => (int) $this->customerNumbers->allocate()])->save();

                $owner = new User;
                $owner->fill(['name' => $ownerName, 'email' => $ownerEmail, 'phone' => $ownerPhone]);
                $owner->forceFill(['tenant_id' => $tenant->id, 'email_verified_at' => now(), 'is_active' => true, 'must_change_password' => true]);
                $owner->password = $password;
                $owner->save();
                $owner->branches()->attach($branch->id, ['is_default' => true]);
                $this->permissions->setPermissionsTeamId($tenant->id);
                $owner->assignRole(SystemRoles::OWNER);

                Audit::log('tenant.provisioned', $tenant, ['slug' => $tenant->slug, 'owner_email' => $ownerEmail, 'branch' => $branch->code, 'register' => $register->code], $actor);

                return new ProvisionedTenant($tenant, $branch, $owner, $password);
            });
        });

        // After commit: the mail driver may be slow or fail, and must never roll the tenant back.
        $result->owner->notify(new TenantOwnerInvitation($result->tenant, $result->temporaryPassword));

        return $result;
    }

    private function phone(mixed $value): ?string
    {
        $raw = is_string($value) ? trim($value) : '';
        if ($raw === '') {
            return null;
        }

        return BdPhone::toE164($raw) ?? throw InvalidAdminData::invalid('The phone number is not a valid Bangladeshi mobile number.');
    }
}
