<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The local ledger of every operation sent to MKESH.
 *
 * One row per debit (C2B) or transfer (B2C). The row is created BEFORE the
 * request goes out — the aggregator rejects a reused id with
 * REFERENCE_ID_ALREADY_IN_USE, so the id must be reserved locally first.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('mkesh_transactions', function (Blueprint $table): void {
            $table->id();

            // 'debit' (C2B) or 'transfer' (B2C)
            $table->string('type', 16)->index();

            // Ids we send to the aggregator, already carrying the partner
            // prefix (e.g. MTL000001).
            $table->string('external_transaction_id')->nullable();   // debit
            $table->string('provider_transaction_id')->nullable();   // transfer
            $table->string('reference_id')->nullable()->index();     // status lookups

            // Ids returned by the platform
            $table->string('financial_transaction_id')->nullable()->index();
            $table->string('approval_id')->nullable();

            // Counterparty + money
            $table->string('msisdn')->nullable()->index();
            $table->decimal('amount', 18, 2);
            $table->string('currency', 3)->default('MZN');

            // PENDING | SUCCESSFUL | FAILED | UNKNOWN
            $table->string('status', 16)->default('PENDING')->index();

            // Platform error code + description when the operation failed
            $table->string('error_code')->nullable();
            $table->text('error_message')->nullable();

            // Free-form link back to whatever this payment is for
            $table->nullableMorphs('payable');

            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            // The ids must be unique per service provider; enforce that locally
            // too so a double-submit fails fast instead of at the aggregator.
            $table->unique(['type', 'external_transaction_id']);
            $table->unique(['type', 'provider_transaction_id']);

            // Drives the "still pending, keep reconciling" query.
            $table->index(['status', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mkesh_transactions');
    }
};
