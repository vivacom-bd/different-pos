<?php

declare(strict_types=1);

namespace App\Exceptions\Domain;

/** A purchase, vendor payment or stock adjustment that breaks a business rule. */
final class InvalidStockDocument extends DomainException
{
    public static function noLines(): self
    {
        return new self('The document has no lines.');
    }

    public static function nonPositiveQuantity(int $line): self
    {
        return new self(sprintf('Line %d: the quantity must be greater than zero.', $line));
    }

    public static function zeroQuantity(int $line): self
    {
        return new self(sprintf('Line %d: the quantity must not be zero.', $line));
    }

    public static function negativeAmount(string $field): self
    {
        return new self(sprintf('%s must not be negative.', $field));
    }

    public static function negativeTotal(): self
    {
        return new self('The discount is larger than the purchase.');
    }

    public static function nonPositivePayment(): self
    {
        return new self('The payment amount must be greater than zero.');
    }

    public static function overpaid(string $number): self
    {
        return new self(sprintf('The payment is more than the amount due on purchase %s.', $number));
    }

    public static function allocationsExceedPayment(): self
    {
        return new self('The allocations add up to more than the payment.');
    }

    public static function untracked(int $line): self
    {
        return new self(sprintf('Line %d: the product does not track stock.', $line));
    }

    public static function notReceived(string $number): self
    {
        return new self(sprintf('Purchase %s is not in the received state.', $number));
    }

    public static function stockConsumed(string $number): self
    {
        return new self(sprintf('Purchase %s cannot be cancelled: part of its stock has already been sold or moved.', $number));
    }

    public static function hasPayments(string $number): self
    {
        return new self(sprintf('Purchase %s cannot be cancelled while payments are allocated to it.', $number));
    }

    public static function wrongVendor(string $number): self
    {
        return new self(sprintf('Purchase %s belongs to another vendor.', $number));
    }

    public static function vendorlessDue(): self
    {
        return new self(__('purchases.vendorless_due'));
    }

    public static function storeCreditPayment(): self
    {
        return new self(__('purchases.store_credit_payment'));
    }
}
