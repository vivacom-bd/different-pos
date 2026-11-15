<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Database\Factories\CourierFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * A delivery courier. The tenant's default one prints on the delivery chalan. Sales keep the courier
 * as plain text (sales.courier_name), so nothing references this table by id.
 *
 * @property int $id
 * @property int $tenant_id
 * @property string $name
 * @property string|null $phone
 * @property bool $is_default
 * @property bool $is_active
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
final class Courier extends Model
{
    use BelongsToTenant;

    /** @use HasFactory<CourierFactory> */
    use HasFactory;

    /** The couriers every new tenant starts with (all active, none default). The data migration carries its own copy. */
    public const array DEFAULT_NAMES = [
        'Steadfast Courier', 'Pathao Courier', 'RedX', 'Sundarban Courier Service', 'SA Paribahan',
        'Janani Express', 'Karatoa Courier Service', 'eCourier', 'Paperfly', 'Delivery Tiger',
    ];

    protected $fillable = ['name', 'phone', 'is_default', 'is_active'];

    /**
     * The register must pick a courier on every sale: the tenant (current context) has active couriers
     * and none of them is the default one to fall back on.
     */
    public static function mustPick(): bool
    {
        $active = self::query()->where('is_active', true);

        return (clone $active)->exists() && ! (clone $active)->where('is_default', true)->exists();
    }

    protected function casts(): array
    {
        return ['is_default' => 'boolean', 'is_active' => 'boolean'];
    }
}
