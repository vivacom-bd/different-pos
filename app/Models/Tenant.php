<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\HasUuid;
use App\Models\Concerns\LogsTenantActivity;
use App\Services\Crypto\PinSealer;
use Database\Factories\TenantFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;

/**
 * One business (a customer of the SaaS). Not tenant-scoped itself.
 *
 * @property int $id
 * @property string $uuid
 * @property string $name
 * @property string $slug
 * @property string|null $legal_name
 * @property string|null $vat_bin
 * @property string|null $vat_registered_address
 * @property string|null $phone
 * @property string|null $email
 * @property string|null $address
 * @property string $currency
 * @property string $timezone
 * @property string $locale
 * @property string $status
 * @property array<string, mixed> $settings
 * @property array<string, mixed>|null $branding
 * @property string $pin_box_public_key
 * @property string $pin_box_secret_key
 * @property int $sync_counter
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
final class Tenant extends Model
{
    /** @use HasFactory<TenantFactory> */
    use HasFactory, HasUuid, LogsTenantActivity;

    public const string STATUS_ACTIVE = 'active';

    public const string STATUS_SUSPENDED = 'suspended';

    /** Defaults merged under the stored settings. */
    public const array DEFAULT_SETTINGS = [
        'tax_inclusive' => true,
        'backdate_days' => 3,
        'require_customer_on_sale' => true, // a sale needs a named customer (no walk-in); offline sales without one are flagged
        'customer_pin_policy' => 'off', // off|optional|required (App\Enums\CustomerPinPolicy)
        'offline_credit_cap' => 0,         // paisa
        'offline_login_days' => 7,
        'lapsed_days' => 90,
        'card_terms' => '',
        'default_credit_limit' => null,    // paisa; applies to customers without their own credit_limit; null = no limit
        'default_unit_id' => null,         // units.id preselected on new products; null = the active "pcs" unit
        'return_days' => null,             // return window in days after the sale; null = no limit
        'sms' => [                         // see App\Sms\SmsSettings; api_token is stored encrypted
            'driver' => 'null',            // null|log|ssl_wireless|onecodesoft
            'sender_id' => '',
            'sid' => '',
            'base_url' => '',
            'api_token' => null,
            'templates' => [],
        ],
    ];

    protected $fillable = [
        'name', 'slug', 'legal_name', 'vat_bin', 'vat_registered_address', 'phone', 'email', 'address',
        'currency', 'timezone', 'locale', 'settings', 'branding',
    ];

    /** The sealed-box secret never leaves the server. */
    protected $hidden = ['pin_box_secret_key', 'sync_counter'];

    protected static function booted(): void
    {
        self::creating(static function (Tenant $tenant): void {
            $tenant->settings = array_replace(self::DEFAULT_SETTINGS, $tenant->getAttribute('settings') ?? []);
            $tenant->status ??= self::STATUS_ACTIVE;

            if (empty($tenant->pin_box_public_key)) {
                $keys = PinSealer::generateKeypair();
                $tenant->pin_box_public_key = $keys['public'];
                $tenant->pin_box_secret_key = $keys['secret'];
            }
        });
    }

    /** A tenant setting with its default applied. */
    public function setting(string $key): mixed
    {
        return $this->settings[$key] ?? self::DEFAULT_SETTINGS[$key] ?? null;
    }

    /** The logo's path on the public disk (tenants.branding.logo_path), or null without one. */
    public function logoPath(): ?string
    {
        $path = $this->branding['logo_path'] ?? null;

        return is_string($path) && $path !== '' ? $path : null;
    }

    /** The public URL of the business logo, or null without one. */
    /**
     * Host-relative URL of the logo ("/storage/tenants/1/logo-x.png"): never APP_URL-absolute, so it
     * works on whatever host the app is opened at (127.0.0.1 vs localhost, a LAN IP, a tunnel).
     */
    public function logoUrl(): ?string
    {
        $path = $this->logoPath();
        if ($path === null) {
            return null;
        }

        $url = Storage::disk('public')->url($path);
        $relative = parse_url($url, PHP_URL_PATH);

        return is_string($relative) && $relative !== '' ? $relative : $url;
    }

    public function isActive(): bool
    {
        return $this->status === self::STATUS_ACTIVE;
    }

    /** @return HasMany<Branch, $this> */
    public function branches(): HasMany
    {
        return $this->hasMany(Branch::class);
    }

    /** @return HasMany<User, $this> */
    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }

    protected function casts(): array
    {
        return [
            'settings' => 'array',
            'branding' => 'array',
            'pin_box_secret_key' => 'encrypted',
            'sync_counter' => 'integer',
        ];
    }
}
