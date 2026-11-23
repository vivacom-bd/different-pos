<?php

declare(strict_types=1);

namespace App\Exceptions\Domain;

/** The courier condition is more than the due, or was set without a courier. */
final class ConditionInvalid extends DomainException
{
    public static function forSale(): self
    {
        return new self('The condition must be a courier sale and not more than the due.');
    }
}
