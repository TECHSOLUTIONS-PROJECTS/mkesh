<?php

declare(strict_types=1);

namespace App\Services;

use App\Jobs\ReconcileMkeshTransaction;
use App\Models\MkeshResponse;
use App\Models\MkeshTransaction;
use TechSolutions\Mkesh\Enum\TransactionStatus;
use TechSolutions\Mkesh\Exception\ErrorResponseException;
use TechSolutions\Mkesh\MkeshClient;
use TechSolutions\Mkesh\Request\DebitRequest;
use TechSolutions\Mkesh\Request\SpTransferRequest;
use TechSolutions\Mkesh\ValueObject\Fri;
use TechSolutions\Mkesh\ValueObject\Money;
use Illuminate\Database\Eloquent\Model;

/**
 * Example service wrapping the two money movements, with local persistence.
 *
 * C2B (charge): the customer gets an APPROVAL_REQUESTING_PENDING SMS and must
 * approve before the request expires. The response is PENDING; the outcome
 * arrives via the debitcompleted callback, with ReconcileMkeshTransaction as
 * the fallback when it does not.
 *
 * B2C (payout): sptransfer moves money from the SP wallet to the customer and
 * settles synchronously.
 */
final class MkeshPaymentService
{
    public function __construct(
        private readonly MkeshClient $mkesh,
    ) {
    }

    /**
     * Charge a customer. Returns the local transaction row, which starts out
     * PENDING and is settled later by the callback or the reconcile job.
     *
     * @param string $msisdn  Customer number in international form, e.g. "258823040400".
     * @param Model|null $payable What this payment is for (order, invoice…).
     */
    public function charge(
        string $msisdn,
        string $amount,
        ?string $reference = null,
        ?Model $payable = null,
    ): MkeshTransaction {
        $config = $this->mkesh->config();

        // The prefixed id is what the platform echoes back in the callback, so
        // it is what we store and match on. Generate and persist it BEFORE
        // sending — a reused id is rejected with REFERENCE_ID_ALREADY_IN_USE.
        $externalId = $config->newTransactionId($reference);

        $transaction = new MkeshTransaction([
            'type' => MkeshTransaction::TYPE_DEBIT,
            'external_transaction_id' => $externalId,
            'reference_id' => $externalId,
            'msisdn' => $msisdn,
            'amount' => $amount,
            'currency' => $config->defaultCurrency,
            'status' => TransactionStatus::PENDING,
        ]);

        if ($payable !== null) {
            $transaction->payable()->associate($payable);
        }

        $transaction->save();

        try {
            // Pass the already-prefixed id: applyPrefix() is idempotent.
            $response = $this->mkesh->debit(new DebitRequest(
                fromFri: Fri::msisdn($msisdn),
                amount: Money::of($amount, $config->defaultCurrency),
                externalTransactionId: $externalId,
            ));
        } catch (ErrorResponseException $e) {
            $this->recordFailure($transaction, $e, 'debitresponse');

            throw $e;
        }

        $transaction->update([
            'financial_transaction_id' => $response->transactionId,
            'approval_id' => $response->approvalId,
            'status' => $response->status,
        ]);

        // Fallback for the "No Response From MKESH" branch of the flow.
        ReconcileMkeshTransaction::dispatch($transaction->id)->delay(now()->addMinutes(2));

        return $transaction;
    }

    /**
     * Pay a customer out of the service-provider wallet. Unlike a debit this
     * settles synchronously — a successful response means the money moved.
     */
    public function payout(string $msisdn, string $amount, ?string $reference = null): MkeshTransaction
    {
        $config = $this->mkesh->config();
        $providerId = $config->newTransactionId($reference);

        $transaction = MkeshTransaction::create([
            'type' => MkeshTransaction::TYPE_TRANSFER,
            'provider_transaction_id' => $providerId,
            'reference_id' => $providerId,
            'msisdn' => $msisdn,
            'amount' => $amount,
            'currency' => $config->defaultCurrency,
            'status' => TransactionStatus::PENDING,
        ]);

        try {
            $response = $this->mkesh->transfer(new SpTransferRequest(
                receivingFri: Fri::msisdn($msisdn),
                amount: Money::of($amount, $config->defaultCurrency),
                providerTransactionId: $providerId,
            ));
        } catch (ErrorResponseException $e) {
            $this->recordFailure($transaction, $e, 'sptransferresponse');

            throw $e;
        }

        $transaction->update([
            'financial_transaction_id' => $response->transactionId,
            'status' => TransactionStatus::SUCCESSFUL,
            'completed_at' => now(),
        ]);

        return $transaction;
    }

    private function recordFailure(
        MkeshTransaction $transaction,
        ErrorResponseException $e,
        string $operation,
    ): void {
        $transaction->update([
            'status' => TransactionStatus::FAILED,
            'error_code' => $e->getErrorCode(),
            'error_message' => $e->getDescription() ?? $e->getMessage(),
            'completed_at' => now(),
        ]);

        MkeshResponse::create([
            'direction' => MkeshResponse::OUTBOUND,
            'operation' => $operation,
            'mkesh_transaction_id' => $transaction->id,
            'reference_id' => $transaction->reference_id,
            'error_code' => $e->getErrorCode(),
            'payload' => $e->getRawXml(),
        ]);
    }
}
