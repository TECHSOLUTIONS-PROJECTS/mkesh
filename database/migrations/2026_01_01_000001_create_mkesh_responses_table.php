<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Audit log of everything MKESH sent us and everything we answered.
 *
 * Two kinds of row:
 *
 *   - direction = 'inbound'  — a debitcompleted / initiatetransfercompleted
 *                              callback the aggregator POSTed to us, plus the
 *                              <ResponseCode> we replied with.
 *   - direction = 'outbound' — the response body we got back from a request we
 *                              made (debit, sptransfer, gettransactionstatus).
 *
 * Callbacks are redelivered on failure, so rows here are NOT unique per
 * transaction — that is the point: this table is how you prove what arrived,
 * when, and what you answered.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('mkesh_responses', function (Blueprint $table): void {
            $table->id();

            // 'inbound' (callback received) | 'outbound' (reply to our request)
            $table->string('direction', 8)->index();

            // debitcompletedrequest | initiatetransfercompletedrequest |
            // debitresponse | sptransferresponse | gettransactionstatusresponse |
            // errorResponse
            $table->string('operation', 64)->nullable()->index();

            // Link back to the ledger row, when we could match one.
            $table->foreignId('mkesh_transaction_id')->nullable()
                ->constrained('mkesh_transactions')->nullOnDelete();

            // Ids echoed by the platform, kept denormalised so an unmatched
            // payload is still searchable.
            $table->string('external_transaction_id')->nullable()->index();
            $table->string('reference_id')->nullable()->index();
            $table->string('financial_transaction_id')->nullable()->index();

            // PENDING | SUCCESSFUL | FAILED | UNKNOWN
            $table->string('status', 16)->nullable()->index();

            // Populated when the body was an <errorResponse>
            $table->string('error_code')->nullable()->index();

            // What we answered an inbound callback with: SUCCESS | FAILURE
            $table->string('response_code', 16)->nullable();

            // HTTP status we returned (inbound) or received (outbound)
            $table->unsignedSmallInteger('http_status')->nullable();

            // The untouched XML — the only thing worth arguing with the
            // provider over when a reconciliation disagrees.
            $table->longText('payload')->nullable();

            $table->timestamps();

            $table->index(['direction', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mkesh_responses');
    }
};
