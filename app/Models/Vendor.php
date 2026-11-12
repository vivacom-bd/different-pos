<?php

declare(strict_types=1);

namespace App\Models;

use App\Casts\MoneyCast;
use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\HasUuid;
use App\Models\Concerns\LogsTenantActivity;
use App\Support\Money\Money;
use App\Support\Phone\BdPhone;
use Database\Factories\VendorFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * A supplier.
 *
 * @property int $id
 * @property string $uuid
 * @property int $tenant_id
 * @property string $name
 * @property string|null $company
 * @property string|null $phone
 * @property string|null $email
 * @property string|null $address
 * @property string|null $notes
 * @property Money $balance
 * @property bool $is_active
 * @property int|null $legacy_id
 * @property int|null $created_by
 * @property int|null $updated_by
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property Carbon|null $deleted_at
 */
final class Vendor extends Model
{
    use BelongsToTenant, HasUuid, LogsTenantActivity, SoftDeletes;

    /** @use HasFactory<VendorFactory> */
    use HasFactory;

    protected $fillable = [
        'name', 'company', 'phone', 'email', 'address', 'notes', 'balance', 'is_active', 'legacy_id', 'created_by',
        'updated_by',
    ];

    protected function casts(): array
    {
        return [
            'balance' => MoneyCast::class,
            'is_active' => 'boolean',
        ];
    }

    /**
     * Every word must match name, company, address or email (contains), or the phone (contains, also by normalised national digits).
     *
     * @param  Builder<Vendor>  $query
     * @return Builder<Vendor>
     */
    public function scopeSearch(Builder $query, string $term): Builder
    {
        $term = trim($term);
        $tokens = BdPhone::searchPattern($term) !== null ? [$term] : (preg_split('/\s+/u', $term, -1, PREG_SPLIT_NO_EMPTY) ?: []);
        foreach ($tokens as $token) {
            $like = '%'.addcslashes($token, '%_\\').'%';
            $phone = BdPhone::searchPattern($token);
            $query->where(static function (Builder $q) use ($like, $phone): void {
                $q->where('name', 'like', $like)->orWhere('company', 'like', $like)
                    ->orWhere('address', 'like', $like)->orWhere('email', 'like', $like)->orWhere('phone', 'like', $like);
                if ($phone !== null) {
                    $q->orWhere('phone', 'like', $phone);
                }
            });
        }

        return $query;
    }
}
