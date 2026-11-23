<?php

declare(strict_types=1);

namespace App\Actions\Sales\Data;

use App\Models\User;
use App\Support\Money\Money;
use Carbon\CarbonInterface;

/**
 * A cart to complete in the current branch (TenantContext). The server re-prices it; `expectedTotal`
 * is what the client showed and only feeds the price_mismatch flag. A null customer is the walk-in.
 * `approver` is a manager who authorised overrides at the counter (discount cap, price, below cost,
 * credit limit); their permissions count next to the actor's. `memberVerified` is set by the
 * controller once a member token (online PIN check) resolved for this customer and cart.
 */
final readonly class SaleDraft
{
    public Discount $orderDiscount;

    /**
     * @param  list<SaleLineDraft>  $lines
     * @param  list<TenderDraft>  $tenders
     */
    public function __construct(
        public array $lines,
        public array $tenders = [],
        public ?int $customerId = null,
        public ?int $registerId = null,
        ?Discount $orderDiscount = null,
        public ?string $note = null,
        public ?CarbonInterface $soldAt = null,
        public ?Money $expectedTotal = null,
        public ?string $clientUuid = null,
        public ?User $approver = null,
        public ?string $courierName = null,
        public ?string $courierTracking = null,
        public bool $memberVerified = false,
        public int $codAmount = 0,
    ) {
        $this->orderDiscount = $orderDiscount ?? Discount::none();
    }
}
