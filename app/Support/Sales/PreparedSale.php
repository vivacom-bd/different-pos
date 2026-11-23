<?php

declare(strict_types=1);

namespace App\Support\Sales;

use App\Actions\Sales\Data\SaleDraft;
use App\Enums\MemberAuth;
use App\Enums\PaymentMethodType;
use App\Enums\SaleOrigin;
use App\Models\Branch;
use App\Models\Customer;
use App\Models\PaymentMethod;
use App\Models\Product;
use App\Models\Register;
use App\Pos\Pricing\PriceCalculator;
use App\Support\Money\Money;
use Carbon\CarbonImmutable;

/**
 * A validated, server-priced cart, ready to be written by CompleteSale (no rows touched yet).
 * `tenders` hold the applied amounts (cash minus change); `exchangeCredit` is the part of an
 * exchange's return value that pays this sale.
 *
 * @phpstan-import-type CartTotals from PriceCalculator
 *
 * @phpstan-type AppliedTender array{method: PaymentMethod, amount: Money, reference: string|null}
 */
final readonly class PreparedSale
{
    /**
     * @param  list<Product>  $products  per line
     * @param  list<int>  $taxRates  per line, basis points
     * @param  CartTotals  $priced
     * @param  list<AppliedTender>  $tenders
     * @param  list<string>  $flags
     * @param  array<string, mixed>|null  $clientTotals
     * @param  list<string>  $overrides  permissions the sale relied on
     */
    public function __construct(
        public SaleDraft $draft,
        public SaleOrigin $origin,
        public Branch $branch,
        public Customer $customer,
        public ?Register $register,
        public array $products,
        public array $taxRates,
        public bool $taxInclusive,
        public array $priced,
        public CarbonImmutable $soldAt,
        public array $tenders,
        public Money $change,
        public Money $exchangeCredit,
        public array $flags,
        public ?array $clientTotals,
        public string $clientUuid,
        public array $overrides,
        public MemberAuth $memberAuth = MemberAuth::None,
        public ?Money $codAmount = null,
    ) {}

    public function total(): Money
    {
        return Money::ofPaisa($this->priced['order']['grand_total']);
    }

    /** Everything that settles the sale: applied tenders + exchange credit. */
    public function paid(): Money
    {
        $paid = $this->exchangeCredit;
        foreach ($this->tenders as $tender) {
            $paid = $paid->plus($tender['amount']);
        }

        return $paid;
    }

    public function due(): Money
    {
        return $this->total()->minus($this->paid());
    }

    /** Applied tenders of real money (not customer credit). */
    public function moneyReceived(): Money
    {
        $sum = Money::zero();
        foreach ($this->tenders as $tender) {
            if (! CustomerAccounts::isCredit($tender['method'])) {
                $sum = $sum->plus($tender['amount']);
            }
        }

        return $sum;
    }

    public function customerCredit(): Money
    {
        $sum = Money::zero();
        foreach ($this->tenders as $tender) {
            if ($tender['method']->type === PaymentMethodType::CustomerCredit) {
                $sum = $sum->plus($tender['amount']);
            }
        }

        return $sum;
    }

    /** @return list<Product> stock-tracked products of the cart */
    public function trackedProducts(): array
    {
        return array_values(array_filter($this->products, static fn (Product $product): bool => $product->track_stock));
    }
}
