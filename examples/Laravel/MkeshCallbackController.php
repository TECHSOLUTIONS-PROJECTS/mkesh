<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\MkeshResponse;
use App\Models\MkeshTransaction;
use TechSolutions\Mkesh\Callback\CallbackResponse;
use TechSolutions\Mkesh\Exception\MkeshException;
use TechSolutions\Mkesh\Laravel\Facades\Mkesh;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;

/**
 * Example controller that receives the debitcompletedrequest callback from the
 * aggregator and reconciles the local transaction.
 *
 * The acknowledgement body must be <ResponseCode>SUCCESS</ResponseCode> — an
 * empty 200 is not enough and the aggregator will keep retrying without it.
 *
 * Register the route outside the CSRF-protected web group, then give the
 * resulting URL to the provider (the endpoint is configured on their side, it
 * is not sent with each debit request):
 *
 *   // routes/api.php
 *   Route::post('/mkesh/callback', MkeshCallbackController::class);
 */
final class MkeshCallbackController extends Controller
{
    public function __invoke(Request $request): Response
    {
        $body = $request->getContent();

        try {
            $callback = Mkesh::parseDebitCompleted($body);
        } catch (MkeshException) {
            // Unparsable body: log it and tell the aggregator not to retry.
            MkeshResponse::create([
                'direction' => MkeshResponse::INBOUND,
                'operation' => 'debitcompletedrequest',
                'http_status' => 400,
                'payload' => $body,
            ]);

            return response('', 400);
        }

        DB::transaction(function () use ($callback, $body): void {
            // externaltransactionid comes back with the configured prefix
            // applied, which is exactly what we stored when sending the debit.
            // Lock the row so two concurrent redeliveries cannot both settle it.
            $transaction = MkeshTransaction::query()
                ->where('type', MkeshTransaction::TYPE_DEBIT)
                ->where('external_transaction_id', $callback->externalTransactionId)
                ->lockForUpdate()
                ->first();

            MkeshResponse::logCallback(
                operation: 'debitcompletedrequest',
                payload: $body,
                transaction: $transaction,
                status: $callback->status,
            );

            // A redelivered callback for an already-settled transaction is
            // logged above but must not overwrite the outcome.
            if ($transaction === null || $transaction->isSettled()) {
                return;
            }

            $transaction->update([
                'financial_transaction_id' => $callback->transactionId,
                'status' => $callback->status,
                'msisdn' => $callback->receiver->msisdn ?? $transaction->msisdn,
                'completed_at' => now(),
            ]);
        });

        $ack = CallbackResponse::success();

        return response($ack->toXml(), 200)
            ->header('Content-Type', CallbackResponse::CONTENT_TYPE);
    }
}
