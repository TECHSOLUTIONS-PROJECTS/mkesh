<?php

declare(strict_types=1);

namespace BrilliantMind\Mkesh\Laravel\Facades;

use Illuminate\Support\Facades\Facade;
use BrilliantMind\Mkesh\Callback\CallbackResponse;
use BrilliantMind\Mkesh\Callback\DebitCompletedNotification;
use BrilliantMind\Mkesh\Callback\InitiateTransferCompletedNotification;
use BrilliantMind\Mkesh\Config\MkeshConfig;
use BrilliantMind\Mkesh\Request\DebitRequest;
use BrilliantMind\Mkesh\Request\GetTransactionStatusRequest;
use BrilliantMind\Mkesh\Request\SpTransferRequest;
use BrilliantMind\Mkesh\Response\DebitResponse;
use BrilliantMind\Mkesh\Response\SpTransferResponse;
use BrilliantMind\Mkesh\Response\TransactionStatusResponse;

/**
 * @method static DebitResponse debit(DebitRequest $request)
 * @method static SpTransferResponse transfer(SpTransferRequest $request)
 * @method static TransactionStatusResponse getTransactionStatus(string|GetTransactionStatusRequest $reference)
 * @method static DebitCompletedNotification parseDebitCompleted(string $xml)
 * @method static InitiateTransferCompletedNotification parseInitiateTransferCompleted(string $xml)
 * @method static CallbackResponse acknowledgeCallback()
 * @method static MkeshConfig config()
 *
 * @see \BrilliantMind\Mkesh\MkeshClient
 */
final class Mkesh extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return 'mkesh';
    }
}
