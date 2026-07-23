<?php

declare(strict_types=1);

namespace BrilliantMind\Mkesh\Exception;

use BrilliantMind\Mkesh\Enum\ErrorCode;
use BrilliantMind\Mkesh\Error\ErrorCodes;

/**
 * Thrown when the aggregator returns an EWP errorResponse envelope, e.g.
 *
 *   <ns0:errorResponse xmlns:ns0="http://www.ericsson.com/lwac"
 *       errorcode="ACCOUNTHOLDER_WITH_FRI_NOT_FOUND">
 *       <arguments name="fri" value="FRI:258823040420/MSISDN"/>
 *   </ns0:errorResponse>
 *
 * The platform error code is exposed via {@see getErrorCode()} and any
 * <arguments> entries via {@see getArguments()}.
 */
class ErrorResponseException extends \RuntimeException implements MkeshException
{
    /**
     * @param array<string, string> $arguments key/value pairs from <arguments>
     */
    public function __construct(
        private readonly string $errorCode,
        private readonly array $arguments = [],
        private readonly ?string $rawXml = null,
    ) {
        $description = ErrorCodes::description($errorCode);
        $message = $description !== null
            ? sprintf('MKESH error response: %s — %s', $errorCode, $description)
            : sprintf('MKESH error response: %s', $errorCode);
        if ($arguments !== []) {
            $pairs = [];
            foreach ($arguments as $name => $value) {
                $pairs[] = sprintf('%s=%s', $name, $value);
            }
            $message .= ' [' . implode(', ', $pairs) . ']';
        }

        parent::__construct($message);
    }

    /** The EWP error code, e.g. "TRANSACTION_NOT_FOUND". */
    public function getErrorCode(): string
    {
        return $this->errorCode;
    }

    /**
     * The error code as an enum, for exhaustive matching and the classification
     * helpers (isRetryable/isDuplicate/isCustomerFault/...). Codes outside the
     * enum map to {@see ErrorCode::UNKNOWN}; {@see getErrorCode()} always keeps
     * the raw string.
     */
    public function code(): ErrorCode
    {
        return ErrorCode::fromWire($this->errorCode);
    }

    /**
     * True when the platform returned any of the given error codes — accepts
     * enum cases or raw strings:
     *
     *   if ($e->is(ErrorCode::TRANSACTION_NOT_FOUND)) { ... retry later ... }
     *   if ($e->is('REFERENCE_ID_ALREADY_IN_USE')) { ... it was already sent ... }
     */
    public function is(ErrorCode|string ...$codes): bool
    {
        foreach ($codes as $code) {
            if ($this->errorCode === ($code instanceof ErrorCode ? $code->value : $code)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Human-readable description for the error code from the platform
     * catalogue, or null when the code is not in the reference.
     */
    public function getDescription(): ?string
    {
        return ErrorCodes::description($this->errorCode);
    }

    /**
     * @return array<string, string>
     */
    public function getArguments(): array
    {
        return $this->arguments;
    }

    public function getArgument(string $name): ?string
    {
        return $this->arguments[$name] ?? null;
    }

    public function getRawXml(): ?string
    {
        return $this->rawXml;
    }
}
