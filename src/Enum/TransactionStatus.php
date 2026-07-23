<?php

declare(strict_types=1);

namespace BrilliantMind\Mkesh\Enum;

/**
 * Financial transaction status as reported by the EWP platform.
 *
 * The aggregator returns at least PENDING / SUCCESSFUL / FAILED. Any unknown
 * value coming from the wire is mapped to {@see self::UNKNOWN} via
 * {@see self::fromWire()} so parsing never throws on a new status.
 */
enum TransactionStatus: string
{
    case PENDING = 'PENDING';
    case SUCCESSFUL = 'SUCCESSFUL';
    case FAILED = 'FAILED';
    case UNKNOWN = 'UNKNOWN';

    /**
     * Aliases seen in the provider documentation for the canonical values —
     * the C2B flow diagram says SUCCESS/FAILURE where the payloads say
     * SUCCESSFUL/FAILED.
     *
     * @var array<string, string>
     */
    private const ALIASES = [
        'SUCCESS' => self::SUCCESSFUL->value,
        'SUCCEEDED' => self::SUCCESSFUL->value,
        'COMPLETED' => self::SUCCESSFUL->value,
        'FAILURE' => self::FAILED->value,
        'FAIL' => self::FAILED->value,
        'REJECTED' => self::FAILED->value,
        'PENDING_APPROVAL' => self::PENDING->value,
        'APPROVAL_REQUESTING_PENDING' => self::PENDING->value,
    ];

    /**
     * Build from a raw wire value, tolerating casing, known aliases and
     * unknown values.
     */
    public static function fromWire(?string $value): self
    {
        if ($value === null || $value === '') {
            return self::UNKNOWN;
        }

        $normalized = strtoupper(trim($value));
        $normalized = self::ALIASES[$normalized] ?? $normalized;

        return self::tryFrom($normalized) ?? self::UNKNOWN;
    }

    /** True once the transaction has reached a terminal state (no longer worth polling). */
    public function isSettled(): bool
    {
        return $this === self::SUCCESSFUL || $this === self::FAILED;
    }

    public function isPending(): bool
    {
        return $this === self::PENDING;
    }

    public function isSuccessful(): bool
    {
        return $this === self::SUCCESSFUL;
    }

    public function isFailed(): bool
    {
        return $this === self::FAILED;
    }
}
