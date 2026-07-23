<?php

declare(strict_types=1);

namespace TechSolutions\Mkesh\Response;

use DOMElement;
use TechSolutions\Mkesh\Enum\TransactionStatus;
use TechSolutions\Mkesh\Xml\XmlReader;

/**
 * gettransactionstatusresponse v1.3 — the recovered status of an operation.
 */
final class TransactionStatusResponse
{
    public function __construct(
        public readonly string $financialTransactionId,
        public readonly TransactionStatus $status,
        public readonly ?string $providerTransactionId = null,
    ) {
    }

    public static function fromXml(string $xml): self
    {
        return self::fromElement(XmlReader::rootOf($xml));
    }

    public static function fromElement(DOMElement $root): self
    {
        return new self(
            financialTransactionId: XmlReader::requireText($root, 'financialtransactionid'),
            status: TransactionStatus::fromWire(XmlReader::text($root, 'status')),
            providerTransactionId: XmlReader::text($root, 'providertransactionid'),
        );
    }

    public function isSuccessful(): bool
    {
        return $this->status->isSuccessful();
    }

    public function isFailed(): bool
    {
        return $this->status->isFailed();
    }
}
