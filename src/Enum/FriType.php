<?php

declare(strict_types=1);

namespace TechSolutions\Mkesh\Enum;

/**
 * Known FRI (Financial Resource Identifier) account types used by EWP.
 *
 * Stored as plain strings on {@see \TechSolutions\Mkesh\ValueObject\Fri} so that
 * unusual types still round-trip; these constants cover the common cases.
 */
final class FriType
{
    /** A mobile subscriber number (e.g. FRI:258XXXXXXXXX/MSISDN). */
    public const MSISDN = 'MSISDN';

    /** A named service-provider user account (e.g. FRI:pagamKesh/USER). */
    public const USER = 'USER';

    /** A mobile money account (e.g. FRI:1360073/MM). */
    public const MOBILE_MONEY = 'MM';

    private function __construct()
    {
    }
}
