<?php

declare(strict_types=1);

namespace App\Exceptions\Domain;

/** The tenant requires a named customer on every sale (require_customer_on_sale); a walk-in sale is refused at the online register. */
final class CustomerRequired extends DomainException
{
    public static function forSale(): self
    {
        return new self('Attach a customer to this sale before completing it.');
    }
}
