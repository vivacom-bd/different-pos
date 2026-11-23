<?php

declare(strict_types=1);

namespace App\Models;

use App\Casts\MoneyCast;
use App\Enums\MemberAuth;
use App\Enums\PaymentStatus;
use App\Enums\SaleOrigin;
use App\Enums\SaleStatus;
use App\Models\Concerns\BelongsToBranch;
use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\HasUuid;
use App\Models\Concerns\LogsTenantActivity;
use App\Support\Money\Money;
use Database\Factories\SaleFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * A sale: held, completed or voided. Completed sales are immutable apart from payment and void fields.
 *
 * @property int $id
 * @property string $uuid
 * @property int $tenant_id
 * @property int $branch_id
 * @property string $client_uuid
 * @property string|null $number
 * @property int|null $register_id
 * @property int|null $exchange_return_id
 * @property int $customer_id
 * @property SaleStatus $status
 * @property Carbon $sold_at
 * @property Money $subtotal
 * @property Money $item_discount_total
 * @property Money $order_discount
 * @property Money $tax_total
 * @property Money $shipping
 * @property Money $rounding
 * @property Money $total
 * @property Money $paid
 * @property Money $change_given
 * @property Money $due
 * @property Money $cod_amount
 * @property Money $returned_total
 * @property Money $cogs_total
 * @property PaymentStatus $payment_status
 * @property string|null $courier_name
 * @property string|null $courier_tracking
 * @property string|null $public_token
 * @property string|null $buyer_vat_bin
 * @property SaleOrigin $origin
 * @property int|null $device_id
 * @property Carbon|null $device_created_at
 * @property Carbon|null $synced_at
 * @property MemberAuth $member_auth
 * @property int|null $member_auth_by
 * @property string|null $member_pin_box
 * @property int|null $membership_card_id
 * @property list<string>|null $sync_flags
 * @property int|null $sync_flags_ack_by
 * @property Carbon|null $sync_flags_ack_at
 * @property array<string, mixed>|null $client_totals
 * @property string|null $note
 * @property int $sold_by
 * @property int|null $voided_by
 * @property Carbon|null $voided_at
 * @property string|null $void_reason
 * @property int|null $legacy_id
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
final class Sale extends Model
{
    use BelongsToBranch, BelongsToTenant, HasUuid, LogsTenantActivity;

    /** @use HasFactory<SaleFactory> */
    use HasFactory;

    protected $fillable = [
        'client_uuid', 'number', 'register_id', 'exchange_return_id', 'customer_id', 'status', 'sold_at', 'subtotal',
        'item_discount_total', 'order_discount', 'tax_total', 'shipping', 'rounding', 'total', 'paid',
        'change_given', 'due', 'cod_amount', 'returned_total', 'cogs_total', 'payment_status', 'courier_name', 'courier_tracking',
        'public_token', 'buyer_vat_bin', 'origin', 'device_id', 'device_created_at', 'synced_at', 'member_auth',
        'member_auth_by', 'member_pin_box', 'membership_card_id', 'sync_flags', 'sync_flags_ack_by',
        'sync_flags_ack_at', 'client_totals', 'note', 'sold_by', 'voided_by', 'voided_at', 'void_reason',
        'legacy_id',
    ];

    protected $hidden = ['member_pin_box'];

    /** @return BelongsTo<Register, $this> */
    public function register(): BelongsTo
    {
        return $this->belongsTo(Register::class, 'register_id');
    }

    /** @return BelongsTo<Customer, $this> */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class, 'customer_id');
    }

    /** @return BelongsTo<User, $this> */
    public function memberAuthBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'member_auth_by');
    }

    /** @return BelongsTo<MembershipCard, $this> */
    public function membershipCard(): BelongsTo
    {
        return $this->belongsTo(MembershipCard::class, 'membership_card_id');
    }

    /** @return BelongsTo<User, $this> */
    public function syncFlagsAckBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'sync_flags_ack_by');
    }

    /** @return BelongsTo<User, $this> */
    public function soldBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'sold_by');
    }

    /** @return BelongsTo<User, $this> */
    public function voidedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'voided_by');
    }

    /** The condition the courier still has to collect: the stored condition, never more than the current due. */
    public function openCondition(): Money
    {
        return $this->cod_amount->compareTo($this->due) < 0 ? $this->cod_amount : $this->due;
    }

    /** What the customer still owes the shop itself (due less the courier's condition). */
    public function dueAfterCondition(): Money
    {
        return $this->due->minus($this->openCondition());
    }

    /** @return HasMany<SaleItem, $this> */
    public function items(): HasMany
    {
        return $this->hasMany(SaleItem::class, 'sale_id');
    }

    /** @return HasMany<SaleReturn, $this> */
    public function returns(): HasMany
    {
        return $this->hasMany(SaleReturn::class, 'sale_id');
    }

    protected function casts(): array
    {
        return [
            'status' => SaleStatus::class,
            'sold_at' => 'datetime',
            'subtotal' => MoneyCast::class,
            'item_discount_total' => MoneyCast::class,
            'order_discount' => MoneyCast::class,
            'tax_total' => MoneyCast::class,
            'shipping' => MoneyCast::class,
            'rounding' => MoneyCast::class,
            'total' => MoneyCast::class,
            'paid' => MoneyCast::class,
            'change_given' => MoneyCast::class,
            'due' => MoneyCast::class,
            'cod_amount' => MoneyCast::class,
            'returned_total' => MoneyCast::class,
            'cogs_total' => MoneyCast::class,
            'payment_status' => PaymentStatus::class,
            'origin' => SaleOrigin::class,
            'device_created_at' => 'datetime',
            'synced_at' => 'datetime',
            'member_auth' => MemberAuth::class,
            'sync_flags' => 'array',
            'sync_flags_ack_at' => 'datetime',
            'client_totals' => 'array',
            'voided_at' => 'datetime',
        ];
    }
}
