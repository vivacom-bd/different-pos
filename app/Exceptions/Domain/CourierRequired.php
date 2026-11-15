<?php

declare(strict_types=1);

namespace App\Exceptions\Domain;

/** The tenant has couriers but no default one, so the online register must name a courier on every sale. */
final class CourierRequired extends DomainException
{
    public static function forSale(): self
    {
        return new self('Choose a courier for this sale before completing it.');
    }
}
