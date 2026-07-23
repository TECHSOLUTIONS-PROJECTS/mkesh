<?php

declare(strict_types=1);

namespace TechSolutions\Mkesh\Enum;

/**
 * The value carried by the <ResponseCode> element your callback endpoint
 * returns to acknowledge a notification from the aggregator.
 *
 * Only SUCCESS is documented by the provider. FAILURE is provided for the
 * negative acknowledgement but its handling on the platform side is not
 * specified — prefer answering SUCCESS and reconciling out of band with
 * gettransactionstatus.
 */
enum CallbackResponseCode: string
{
    case SUCCESS = 'SUCCESS';
    case FAILURE = 'FAILURE';

    public function isSuccess(): bool
    {
        return $this === self::SUCCESS;
    }
}
