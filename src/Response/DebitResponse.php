<?php

declare(strict_types=1);

namespace BrilliantMind\Mkesh\Response;

use DOMElement;
use BrilliantMind\Mkesh\Enum\TransactionStatus;
use BrilliantMind\Mkesh\Xml\XmlReader;

/**
 * debitresponse v1.1 — returned synchronously from a debit (C2B) request.
 *
 * The status is typically PENDING: the customer still has to approve the debit,
 * and the final outcome arrives later via the debitcompleted callback.
 */
final class DebitResponse
{
    public function __construct(
        public readonly string $transactionId,
        public readonly TransactionStatus $status,
        public readonly ?string $approvalId = null,
    ) {
    }

    public static function fromXml(string $xml): self
    {
        return self::fromElement(XmlReader::rootOf($xml));
    }

    public static function fromElement(DOMElement $root): self
    {
        return new self(
            transactionId: XmlReader::requireText($root, 'transactionid'),
            status: TransactionStatus::fromWire(XmlReader::text($root, 'status')),
            approvalId: XmlReader::text($root, 'approvalid'),
        );
    }

    public function isPending(): bool
    {
        return $this->status->isPending();
    }
}
