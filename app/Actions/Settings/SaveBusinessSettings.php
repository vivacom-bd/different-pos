<?php

declare(strict_types=1);

namespace App\Actions\Settings;

use App\Enums\CustomerPinPolicy;
use App\Exceptions\Domain\InvalidAdminData;
use App\Models\Tenant;
use App\Models\Unit;
use App\Models\User;
use App\Support\Audit\Audit;
use App\Support\Database\Transaction;
use App\Support\Tenancy\TenantContext;
use DateTimeZone;

/** Updates the current business: profile fields on the tenant row, behaviour switches in tenants.settings. */
final readonly class SaveBusinessSettings
{
    private const array PROFILE = ['name', 'legal_name', 'vat_bin', 'vat_registered_address', 'phone', 'email', 'address', 'currency', 'timezone', 'locale'];

    private const array SETTINGS = ['tax_inclusive', 'backdate_days', 'return_days', 'default_unit_id', 'default_credit_limit', 'customer_pin_policy', 'offline_credit_cap'];

    /** @param array<string, mixed> $data */
    public function handle(User $actor, array $data): Tenant
    {
        return Transaction::run(function () use ($actor, $data): Tenant {
            $tenant = Tenant::query()->findOrFail(app(TenantContext::class)->tenantId());

            $profile = array_intersect_key($data, array_flip(self::PROFILE));
            if (array_key_exists('name', $profile) && trim((string) $profile['name']) === '') {
                throw InvalidAdminData::required('Business name');
            }
            if (isset($profile['locale']) && ! in_array($profile['locale'], ['en', 'bn'], true)) {
                throw InvalidAdminData::invalid('The locale must be en or bn.');
            }
            if (isset($profile['timezone']) && ! in_array($profile['timezone'], DateTimeZone::listIdentifiers(), true)) {
                throw InvalidAdminData::invalid('Unknown timezone.');
            }
            if (isset($profile['currency'])) {
                $profile['currency'] = strtoupper((string) $profile['currency']);
                if (preg_match('/^[A-Z]{3}$/', $profile['currency']) !== 1) {
                    throw InvalidAdminData::invalid('The currency must be a three-letter code.');
                }
            }
            foreach (['legal_name', 'vat_bin', 'vat_registered_address', 'phone', 'email', 'address'] as $nullable) {
                if (array_key_exists($nullable, $profile)) {
                    $profile[$nullable] = trim((string) $profile[$nullable]) ?: null;
                }
            }

            $settings = $tenant->settings;
            foreach (array_intersect_key($data, array_flip(self::SETTINGS)) as $key => $value) {
                $settings[$key] = $this->setting($key, $value);
            }

            $tenant->fill([...$profile, 'settings' => $settings]);
            $tenant->save();

            Audit::log('settings.business_updated', $tenant, ['changed' => array_keys($tenant->getChanges())], $actor);

            return $tenant;
        });
    }

    private function setting(string $key, mixed $value): mixed
    {
        return match ($key) {
            'tax_inclusive' => (bool) $value,
            'customer_pin_policy' => is_string($value) && CustomerPinPolicy::tryFrom($value) instanceof CustomerPinPolicy ? $value : throw InvalidAdminData::invalid('Unknown customer PIN policy.'),
            'backdate_days', 'offline_credit_cap' => $this->whole($value, false),
            'default_unit_id' => $this->unit($value),
            // return_days, default_credit_limit: empty means "no limit"
            default => $this->whole($value, true),
        };
    }

    /** An active unit of this tenant (the scoped lookup 404s across tenants), or null to fall back to "pcs". */
    private function unit(mixed $value): ?int
    {
        $id = $this->whole($value, true);
        if ($id === null) {
            return null;
        }
        if (! Unit::query()->whereKey($id)->where('is_active', true)->exists()) {
            throw InvalidAdminData::invalid('The default unit must be an active unit.');
        }

        return $id;
    }

    private function whole(mixed $value, bool $nullable): ?int
    {
        if ($value === null || $value === '') {
            return $nullable ? null : 0;
        }
        if (! is_int($value) && (! is_string($value) || ! ctype_digit($value))) {
            throw InvalidAdminData::invalid('A whole number is required.');
        }
        if ((int) $value < 0) {
            throw InvalidAdminData::invalid('The value cannot be negative.');
        }

        return (int) $value;
    }
}
