<?php

declare(strict_types=1);

namespace TechSolutions\Mkesh\Laravel\Facades;

use Illuminate\Support\Facades\Facade;
use TechSolutions\Mkesh\Callback\CallbackResponse;
use TechSolutions\Mkesh\Callback\DebitCompletedNotification;
use TechSolutions\Mkesh\Callback\InitiateTransferCompletedNotification;
use TechSolutions\Mkesh\Config\MkeshConfig;
use TechSolutions\Mkesh\Request\DebitRequest;
use TechSolutions\Mkesh\Request\GetTransactionStatusRequest;
use TechSolutions\Mkesh\Request\SpTransferRequest;
use TechSolutions\Mkesh\Response\DebitResponse;
use TechSolutions\Mkesh\Response\SpTransferResponse;
use TechSolutions\Mkesh\Response\TransactionStatusResponse;

/**
 * @method static DebitResponse debit(DebitRequest $request)
 * @method static SpTransferResponse transfer(SpTransferRequest $request)
 * @method static TransactionStatusResponse getTransactionStatus(string|GetTransactionStatusRequest $reference)
 * @method static DebitCompletedNotification parseDebitCompleted(string $xml)
 * @method static InitiateTransferCompletedNotification parseInitiateTransferCompleted(string $xml)
 * @method static CallbackResponse acknowledgeCallback()
 * @method static MkeshConfig config()
 *
 * @see \TechSolutions\Mkesh\MkeshClient
 */
final class Mkesh extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return 'mkesh';
    }
}
