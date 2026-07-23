<?php

declare(strict_types=1);

namespace BrilliantMind\Mkesh\Enum;

use BrilliantMind\Mkesh\Error\ErrorCodes;

/**
 * The platform error codes an integration actually branches on.
 *
 * The EWP catalogue has ~670 codes; enumerating them all would be a second copy
 * of {@see ErrorCodes} with no benefit. This enum covers the ones that change
 * what your application *does* — retry, refund, tell the customer, give up —
 * and maps anything else to {@see self::UNKNOWN}. The raw string is always
 * available on the exception via getErrorCode(), and every code (enumerated or
 * not) still resolves to a description through {@see ErrorCodes}.
 */
enum ErrorCode: string
{
    // ---- Lookup / idempotency -------------------------------------------
    case TRANSACTION_NOT_FOUND = 'TRANSACTION_NOT_FOUND';
    case REFERENCE_ID_ALREADY_IN_USE = 'REFERENCE_ID_ALREADY_IN_USE';
    case AMBIGUOUS_REFERENCE_ID = 'AMBIGUOUS_REFERENCE_ID';
    case EXPIRED_OR_INVALID_TRANSACTION_ID = 'EXPIRED_OR_INVALID_TRANSACTION_ID';

    // ---- Counterparty ----------------------------------------------------
    case ACCOUNTHOLDER_WITH_FRI_NOT_FOUND = 'ACCOUNTHOLDER_WITH_FRI_NOT_FOUND';
    case ACCOUNTHOLDER_WITH_MSISDN_NOT_FOUND = 'ACCOUNTHOLDER_WITH_MSISDN_NOT_FOUND';
    case ACCOUNTHOLDER_NOT_ACTIVE = 'ACCOUNTHOLDER_NOT_ACTIVE';
    case AUTHORIZATION_ACCOUNTHOLDER_NOT_ACTIVE = 'AUTHORIZATION_ACCOUNTHOLDER_NOT_ACTIVE';
    case ACCOUNT_NOT_FOUND = 'ACCOUNT_NOT_FOUND';

    // ---- Money -----------------------------------------------------------
    case AUTHORIZATION_CURRENT_BALANCE_TOO_LOW = 'AUTHORIZATION_CURRENT_BALANCE_TOO_LOW';
    case AUTHORIZATION_MAX_TRANSFER_AMOUNT = 'AUTHORIZATION_MAX_TRANSFER_AMOUNT';
    case AUTHORIZATION_MAXIMUM_AMOUNT_ALLOWED_TO_SEND = 'AUTHORIZATION_MAXIMUM_AMOUNT_ALLOWED_TO_SEND';
    case AUTHORIZATION_MAXIMUM_AMOUNT_ALLOWED_TO_RECEIVE = 'AUTHORIZATION_MAXIMUM_AMOUNT_ALLOWED_TO_RECEIVE';
    case AMOUNT_INVALID = 'AMOUNT_INVALID';
    case INVALID_CURRENCY = 'INVALID_CURRENCY';
    case CURRENCY_NOT_SUPPORTED = 'CURRENCY_NOT_SUPPORTED';

    // ---- Approval (the C2B pending window) -------------------------------
    case TRANSACTION_REQUEST_EXPIRED = 'TRANSACTION_REQUEST_EXPIRED';
    case INCORRECT_PIN = 'INCORRECT_PIN';
    case QUEUED_FOR_APPROVAL = 'QUEUED_FOR_APPROVAL';
    case INVALID_APPROVAL_TRANSACTION_STATUS = 'INVALID_APPROVAL_TRANSACTION_STATUS';
    case RETRY_FROM_BEGINNING = 'COULD_NOT_PERFORM_APPROVAL_OF_OPERATION_PLEASE_RETRY_OPERATION_FROM_BEGINNING';

    // ---- Access ----------------------------------------------------------
    case AUTHORIZATION_FAILED = 'AUTHORIZATION_FAILED';

    /** Anything not enumerated above. */
    case UNKNOWN = 'UNKNOWN';

    /**
     * Map a raw platform code, falling back to {@see self::UNKNOWN} so a new
     * code from the platform never breaks parsing.
     */
    public static function fromWire(?string $code): self
    {
        if ($code === null || $code === '') {
            return self::UNKNOWN;
        }

        return self::tryFrom(strtoupper(trim($code))) ?? self::UNKNOWN;
    }

    /** Human-readable description from the full platform catalogue. */
    public function description(): ?string
    {
        return ErrorCodes::description($this->value);
    }

    /**
     * Transient conditions where retrying the *same* request later is sensible.
     *
     * TRANSACTION_NOT_FOUND is here because the platform may not have
     * registered a just-submitted transaction yet — it is the expected answer
     * when you poll gettransactionstatus too early.
     */
    public function isRetryable(): bool
    {
        return match ($this) {
            self::TRANSACTION_NOT_FOUND,
            self::QUEUED_FOR_APPROVAL,
            self::RETRY_FROM_BEGINNING => true,
            default => false,
        };
    }

    /**
     * The id was already used. The original request very likely went through —
     * query gettransactionstatus instead of resending with a new id.
     */
    public function isDuplicate(): bool
    {
        return $this === self::REFERENCE_ID_ALREADY_IN_USE;
    }

    /**
     * Something about the customer blocked the payment. Surface a message to
     * them; resending the identical request will not help.
     */
    public function isCustomerFault(): bool
    {
        return match ($this) {
            self::ACCOUNTHOLDER_WITH_FRI_NOT_FOUND,
            self::ACCOUNTHOLDER_WITH_MSISDN_NOT_FOUND,
            self::ACCOUNTHOLDER_NOT_ACTIVE,
            self::AUTHORIZATION_ACCOUNTHOLDER_NOT_ACTIVE,
            self::ACCOUNT_NOT_FOUND,
            self::AUTHORIZATION_CURRENT_BALANCE_TOO_LOW,
            self::AUTHORIZATION_MAX_TRANSFER_AMOUNT,
            self::AUTHORIZATION_MAXIMUM_AMOUNT_ALLOWED_TO_SEND,
            self::AUTHORIZATION_MAXIMUM_AMOUNT_ALLOWED_TO_RECEIVE,
            self::TRANSACTION_REQUEST_EXPIRED,
            self::INCORRECT_PIN => true,
            default => false,
        };
    }

    /** The customer let the approval window lapse without confirming. */
    public function isExpired(): bool
    {
        return match ($this) {
            self::TRANSACTION_REQUEST_EXPIRED,
            self::EXPIRED_OR_INVALID_TRANSACTION_ID => true,
            default => false,
        };
    }

    /** Bad credentials, unregistered source IP, or a permission problem. */
    public function isAuthFailure(): bool
    {
        return $this === self::AUTHORIZATION_FAILED;
    }
}
