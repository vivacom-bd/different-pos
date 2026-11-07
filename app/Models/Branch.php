<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\HasUuid;
use App\Models\Concerns\LogsTenantActivity;
use Database\Factories\BranchFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * A shop of a tenant.
 *
 * @property int $id
 * @property string $uuid
 * @property int $tenant_id
 * @property string $code
 * @property string $name
 * @property string|null $phone
 * @property string|null $address
 * @property string|null $logo_path
 * @property string|null $receipt_header
 * @property string|null $receipt_footer
 * @property array<string, mixed> $settings
 * @property bool $is_active
 * @property int|null $legacy_id
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property Carbon|null $deleted_at
 */
final class Branch extends Model
{
    use BelongsToTenant, HasUuid, LogsTenantActivity, SoftDeletes;

    /** @use HasFactory<BranchFactory> */
    use HasFactory;

    public const array DEFAULT_SETTINGS = [
        'allow_negative_stock' => false,
        'batch_selection' => 'auto', // auto|manual (legacy is_use_stock_invoice)
        'cash_rounding' => 0,        // paisa step; 0 = none
        'auto_print' => true,
        'paper' => 'a4',             // 80|58|a4 — A4 is the default; a branch may pick a roll
    ];

    protected $fillable = [
        'code', 'name', 'phone', 'address', 'logo_path', 'receipt_header', 'receipt_footer', 'settings', 'is_active',
    ];

    protected static function booted(): void
    {
        self::creating(static function (Branch $branch): void {
            $branch->settings = array_replace(self::DEFAULT_SETTINGS, $branch->getAttribute('settings') ?? []);
        });
    }

    public function setting(string $key): mixed
    {
        return $this->settings[$key] ?? self::DEFAULT_SETTINGS[$key] ?? null;
    }

    /** @return BelongsToMany<User, $this> */
    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class)->withPivot('is_default');
    }

    protected function casts(): array
    {
        return [
            'settings' => 'array',
            'is_active' => 'boolean',
        ];
    }
}
