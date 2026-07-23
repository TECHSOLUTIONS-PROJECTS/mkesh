<?php

declare(strict_types=1);

namespace TechSolutions\Mkesh\Tests;

use PHPUnit\Framework\TestCase;
use TechSolutions\Mkesh\Exception\InvalidArgumentException;
use TechSolutions\Mkesh\ValueObject\Fri;
use TechSolutions\Mkesh\ValueObject\Money;

final class ValueObjectTest extends TestCase
{
    public function test_fri_factories_render_canonical_string(): void
    {
        self::assertSame('FRI:258823040400/MSISDN', (string) Fri::msisdn('258823040400'));
        self::assertSame('FRI:pagamKesh/USER', (string) Fri::user('pagamKesh'));
        self::assertSame('FRI:1360073/MM', (string) Fri::mobileMoney('1360073'));
    }

    public function test_fri_round_trips_through_from_string(): void
    {
        $fri = Fri::fromString('FRI:258823040400/MSISDN');

        self::assertSame('258823040400', $fri->value);
        self::assertSame('MSISDN', $fri->type);
        self::assertSame('FRI:258823040400/MSISDN', (string) $fri);
    }

    public function test_fri_from_string_rejects_bad_input(): void
    {
        $this->expectException(InvalidArgumentException::class);
        Fri::fromString('258823040400/MSISDN');
    }

    public function test_money_normalizes_amount_and_uppercases_currency(): void
    {
        self::assertSame('25', Money::of(25)->amount);
        self::assertSame('MZN', Money::of(25)->currency);
        self::assertSame('25.5', Money::of(25.50)->amount);
        self::assertSame('USD', Money::of('10', 'usd')->currency);
    }

    public function test_money_rejects_non_positive_amounts(): void
    {
        $this->expectException(InvalidArgumentException::class);
        Money::of(0);
    }
}
