<?php

declare(strict_types=1);

namespace App\Models;

use App\Casts\MoneyCast;
use App\Enums\PaymentStatus;
use App\Enums\PurchaseStatus;
use App\Models\Concerns\BelongsToBranch;
use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\HasUuid;
use App\Models\Concerns\LogsTenantActivity;
use App\Support\Money\Money;
use Database\Factories\PurchaseFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * A stock purchase from a vendor.
 *
 * @property int $id
 * @property string $uuid
 * @property int $tenant_id
 * @property int $branch_id
 * @property int|null $vendor_id
 * @property string $number
 * @property string|null $vendor_ref
 * @property PurchaseStatus $status
 * @property Carbon $purchased_at
 * @property Carbon|null $received_at
 * @property Money $subtotal
 * @property Money $discount
 * @property Money $tax_total
 * @property Money $shipping
 * @property Money $total
 * @property Money $paid
 * @property Money $due
 * @property PaymentStatus $payment_status
 * @property string|null $note
 * @property int|null $legacy_id
 * @property int|null $created_by
 * @property int|null $updated_by
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property Carbon|null $deleted_at
 */
final class Purchase extends Model
{
    use BelongsToBranch, BelongsToTenant, HasUuid, LogsTenantActivity, SoftDeletes;

    /** @use HasFactory<PurchaseFactory> */
    use HasFactory;

    protected $fillable = [
        'vendor_id', 'number', 'vendor_ref', 'status', 'purchased_at', 'received_at', 'subtotal', 'discount',
        'tax_total', 'shipping', 'total', 'paid', 'due', 'payment_status', 'note', 'legacy_id', 'created_by',
        'updated_by',
    ];

    /** @return BelongsTo<Vendor, $this> */
    public function vendor(): BelongsTo
    {
        return $this->belongsTo(Vendor::class, 'vendor_id');
    }

    /** @return HasMany<PurchaseItem, $this> */
    public function items(): HasMany
    {
        return $this->hasMany(PurchaseItem::class, 'purchase_id');
    }

    protected function casts(): array
    {
        return [
            'status' => PurchaseStatus::class,
            'purchased_at' => 'datetime',
            'received_at' => 'datetime',
            'subtotal' => MoneyCast::class,
            'discount' => MoneyCast::class,
            'tax_total' => MoneyCast::class,
            'shipping' => MoneyCast::class,
            'total' => MoneyCast::class,
            'paid' => MoneyCast::class,
            'due' => MoneyCast::class,
            'payment_status' => PaymentStatus::class,
        ];
    }
}
