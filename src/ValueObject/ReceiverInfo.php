<?php

declare(strict_types=1);

namespace TechSolutions\Mkesh\ValueObject;

/**
 * receiverinfo v1.0 — information about the receiving party, as carried in the
 * debitcompleted callback.
 */
final class ReceiverInfo
{
    public function __construct(
        public readonly ?string $fri = null,
        public readonly ?string $msisdn = null,
        public readonly ?string $language = null,
    ) {
    }
}
