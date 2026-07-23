<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\MkeshResponse;
use App\Models\MkeshTransaction;
use BrilliantMind\Mkesh\Enum\TransactionStatus;
use BrilliantMind\Mkesh\Exception\ErrorResponseException;
use BrilliantMind\Mkesh\Exception\TransportException;
use BrilliantMind\Mkesh\MkeshClient;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Implements the "No Response From MKESH" branch of the C2B flow: when a debit
 * comes back PENDING and no debitcompleted callback arrives, poll
 * gettransactionstatus by referenceid until the transaction settles.
 *
 * Dispatch it with a delay right after sending the debit, e.g.:
 *
 *   ReconcileMkeshTransaction::dispatch($transaction->id)->delay(now()->addMinutes(2));
 *
 * The job is idempotent and races the callback harmlessly: whichever arrives
 * first writes the terminal status, the other sees isSettled() and stops.
 */
final class ReconcileMkeshTransaction implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    /** Retry the whole job this many times before giving up. */
    public int $tries = 8;

    public function __construct(
        private readonly int $transactionId,
    ) {
    }

    /** Back off progressively — approval can take the customer several minutes. */
    public function backoff(): array
    {
        return [60, 120, 300, 600, 900, 1800, 3600];
    }

    public function handle(MkeshClient $mkesh): void
    {
        $transaction = MkeshTransaction::find($this->transactionId);

        if ($transaction === null || $transaction->isSettled()) {
            return;
        }

        try {
            $status = $mkesh->getTransactionStatus($transaction->reference_id);
        } catch (ErrorResponseException $e) {
            $code = $e->code();

            MkeshResponse::create([
                'direction' => MkeshResponse::OUTBOUND,
                'operation' => 'gettransactionstatusresponse',
                'mkesh_transaction_id' => $transaction->id,
                'reference_id' => $transaction->reference_id,
                'error_code' => $e->getErrorCode(),
                'payload' => $e->getRawXml(),
            ]);

            // TRANSACTION_NOT_FOUND here usually means "not registered yet" —
            // we polled too early. Same for the other transient codes.
            if ($code->isRetryable()) {
                $this->release(300);

                return;
            }

            $transaction->update([
                'status' => TransactionStatus::FAILED,
                'error_code' => $e->getErrorCode(),
                'error_message' => $e->getDescription() ?? $e->getMessage(),
                'completed_at' => now(),
            ]);

            return;
        } catch (TransportException $e) {
            // Network/parse problem — let the queue retry with backoff.
            throw new \RuntimeException('MKESH status check failed, will retry.', 0, $e);
        }

        MkeshResponse::create([
            'direction' => MkeshResponse::OUTBOUND,
            'operation' => 'gettransactionstatusresponse',
            'mkesh_transaction_id' => $transaction->id,
            'reference_id' => $transaction->reference_id,
            'financial_transaction_id' => $status->financialTransactionId,
            'status' => $status->status,
        ]);

        if (!$status->status->isSettled()) {
            // Still awaiting the customer's approval.
            $this->release($this->backoff()[$this->attempts() - 1] ?? 3600);

            return;
        }

        $transaction->update([
            'financial_transaction_id' => $status->financialTransactionId,
            'status' => $status->status,
            'completed_at' => now(),
        ]);
    }
}
