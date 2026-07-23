<?php

declare(strict_types=1);

namespace BrilliantMind\Mkesh\Callback;

use BrilliantMind\Mkesh\Enum\CallbackResponseCode;
use Stringable;

/**
 * The body the partner must return when acknowledging a callback from the
 * aggregator (debitcompletedrequest v1_2).
 *
 * The integration sheet specifies the acknowledgement as:
 *
 *     <?xml version="1.0" encoding="utf-8"?>
 *     <ResponseCode>SUCCESS</ResponseCode>
 *
 * An empty 200 is NOT enough — the aggregator looks for this document and will
 * keep retrying the callback without it.
 */
final class CallbackResponse implements Stringable
{
    /** Content-Type to send alongside the body. */
    public const CONTENT_TYPE = 'text/xml; charset=utf-8';

    private function __construct(
        public readonly CallbackResponseCode $code,
    ) {
    }

    /** The documented acknowledgement: the callback was accepted and stored. */
    public static function success(): self
    {
        return new self(CallbackResponseCode::SUCCESS);
    }

    /**
     * Negative acknowledgement. Not documented by the provider — see
     * {@see CallbackResponseCode}.
     */
    public static function failure(): self
    {
        return new self(CallbackResponseCode::FAILURE);
    }

    public static function of(CallbackResponseCode $code): self
    {
        return new self($code);
    }

    public function toXml(): string
    {
        // The enum guarantees the value is a safe XML token, so no escaping
        // is needed here.
        return '<?xml version="1.0" encoding="utf-8"?>'
            . '<ResponseCode>' . $this->code->value . '</ResponseCode>';
    }

    public function __toString(): string
    {
        return $this->toXml();
    }
}
