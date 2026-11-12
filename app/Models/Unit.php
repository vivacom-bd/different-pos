<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\Syncable;
use App\Support\Tenancy\TenantContext;
use Database\Factories\UnitFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * A unit of measure.
 *
 * @property int $id
 * @property int $tenant_id
 * @property string $name
 * @property string $short_name
 * @property bool $allows_decimal
 * @property bool $is_active
 * @property int|null $sync_version
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
final class Unit extends Model
{
    use BelongsToTenant, Syncable;

    /** @use HasFactory<UnitFactory> */
    use HasFactory;

    protected $fillable = [
        'name', 'short_name', 'allows_decimal', 'is_active',
    ];

    protected function casts(): array
    {
        return [
            'allows_decimal' => 'boolean',
            'is_active' => 'boolean',
        ];
    }

    /**
     * The unit new products preselect: the tenant's "default unit" setting when it is still an active unit,
     * else the active "pcs" unit, else null (callers fall back to the first option).
     */
    public static function defaultId(): ?int
    {
        $configured = app(TenantContext::class)->tenant()->setting('default_unit_id');
        if (is_int($configured) && self::query()->whereKey($configured)->where('is_active', true)->exists()) {
            return $configured;
        }

        $pcs = self::query()->where('is_active', true)->whereRaw('LOWER(short_name) = ?', ['pcs'])->orderBy('id')->value('id');

        return is_numeric($pcs) ? (int) $pcs : null;
    }
}
