<?php

declare(strict_types=1);

namespace App\Models;

use TechSolutions\Mkesh\Enum\TransactionStatus;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * Example Eloquent model for the mkesh_transactions table.
 *
 * The primary key is a UUID (see the migration): HasUuids mints it on create
 * and switches the key type/auto-increment flags for you.
 *
 * @property string      $id
 * @property string      $type
 * @property string|null $external_transaction_id
 * @property string|null $provider_transaction_id
 * @property string|null $reference_id
 * @property string|null $financial_transaction_id
 * @property string|null $approval_id
 * @property string|null $msisdn
 * @property string      $amount
 * @property string      $currency
 * @property TransactionStatus $status
 * @property string|null $error_code
 * @property string|null $error_message
 * @property \Illuminate\Support\Carbon|null $completed_at
 */
class MkeshTransaction extends Model
{
    use HasUuids;

    public const TYPE_DEBIT = 'debit';
    public const TYPE_TRANSFER = 'transfer';

    protected $fillable = [
        'type',
        'external_transaction_id',
        'provider_transaction_id',
        'reference_id',
        'financial_transaction_id',
        'approval_id',
        'msisdn',
        'amount',
        'currency',
        'status',
        'error_code',
        'error_message',
        'completed_at',
    ];

    protected $casts = [
        // Cast straight to the SDK enum, so $transaction->status->isSettled()
        // works the same as on a parsed response.
        'status' => TransactionStatus::class,
        'completed_at' => 'datetime',
    ];

    /** Every callback and response body seen for this transaction. */
    public function responses(): HasMany
    {
        return $this->hasMany(MkeshResponse::class);
    }

    /** Whatever this payment is for — an order, an invoice, a licence… */
    public function payable(): MorphTo
    {
        return $this->morphTo();
    }

    public function isPending(): bool
    {
        return $this->status->isPending();
    }

    public function isSuccessful(): bool
    {
        return $this->status->isSuccessful();
    }

    public function isFailed(): bool
    {
        return $this->status->isFailed();
    }

    /** Terminal state — no longer worth polling or updating from a callback. */
    public function isSettled(): bool
    {
        return $this->status->isSettled();
    }
}
