<?php

declare(strict_types=1);

namespace App\Models;

use TechSolutions\Mkesh\Enum\CallbackResponseCode;
use TechSolutions\Mkesh\Enum\TransactionStatus;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Example Eloquent model for the mkesh_responses audit table.
 *
 * Write a row for every callback you receive and every response body you get
 * back. When a reconciliation with the provider disagrees, the raw `payload`
 * is the only thing worth arguing over.
 *
 * @property string      $id
 * @property string      $direction
 * @property string|null $operation
 * @property string|null $mkesh_transaction_id
 * @property string|null $external_transaction_id
 * @property string|null $reference_id
 * @property string|null $financial_transaction_id
 * @property TransactionStatus|null $status
 * @property string|null $error_code
 * @property CallbackResponseCode|null $response_code
 * @property int|null    $http_status
 * @property string|null $payload
 */
class MkeshResponse extends Model
{
    use HasUuids;

    /** A callback the aggregator POSTed to us. */
    public const INBOUND = 'inbound';

    /** A response body we got back from a request we made. */
    public const OUTBOUND = 'outbound';

    protected $fillable = [
        'direction',
        'operation',
        'mkesh_transaction_id',
        'external_transaction_id',
        'reference_id',
        'financial_transaction_id',
        'status',
        'error_code',
        'response_code',
        'http_status',
        'payload',
    ];

    protected $casts = [
        'status' => TransactionStatus::class,
        'response_code' => CallbackResponseCode::class,
    ];

    public function transaction(): BelongsTo
    {
        return $this->belongsTo(MkeshTransaction::class, 'mkesh_transaction_id');
    }

    /**
     * Record an inbound callback together with the acknowledgement we returned.
     */
    public static function logCallback(
        string $operation,
        string $payload,
        ?MkeshTransaction $transaction = null,
        ?TransactionStatus $status = null,
        CallbackResponseCode $responseCode = CallbackResponseCode::SUCCESS,
    ): self {
        return self::create([
            'direction' => self::INBOUND,
            'operation' => $operation,
            'mkesh_transaction_id' => $transaction?->id,
            'external_transaction_id' => $transaction?->external_transaction_id,
            'reference_id' => $transaction?->reference_id,
            'status' => $status,
            'response_code' => $responseCode,
            'http_status' => 200,
            'payload' => $payload,
        ]);
    }
}
