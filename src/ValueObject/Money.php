<?php

declare(strict_types=1);

namespace TechSolutions\Mkesh\ValueObject;

use Stringable;
use TechSolutions\Mkesh\Exception\InvalidArgumentException;

/**
 * A monetary amount with an ISO currency code.
 *
 * The amount is kept as a normalised decimal string to avoid float rounding
 * surprises when it is serialised into the request XML.
 */
final class Money implements Stringable
{
    public readonly string $amount;
    public readonly string $currency;

    public function __construct(string|int|float $amount, string $currency = 'MZN')
    {
        $normalized = self::normalize($amount);

        if (!is_numeric($normalized)) {
            throw new InvalidArgumentException(sprintf('Amount "%s" is not numeric.', (string) $amount));
        }
        if ((float) $normalized <= 0.0) {
            throw new InvalidArgumentException('Amount must be a positive value.');
        }

        $currency = strtoupper(trim($currency));
        if ($currency === '') {
            throw new InvalidArgumentException('Currency cannot be empty.');
        }

        $this->amount = $normalized;
        $this->currency = $currency;
    }

    public static function of(string|int|float $amount, string $currency = 'MZN'): self
    {
        return new self($amount, $currency);
    }

    private static function normalize(string|int|float $amount): string
    {
        if (is_int($amount)) {
            return (string) $amount;
        }

        if (is_float($amount)) {
            // Avoid scientific notation and trailing zeros while keeping cents.
            $formatted = rtrim(rtrim(sprintf('%.4f', $amount), '0'), '.');

            return $formatted === '' ? '0' : $formatted;
        }

        return trim($amount);
    }

    public function __toString(): string
    {
        return sprintf('%s %s', $this->amount, $this->currency);
    }
}
