<?php

declare(strict_types=1);

namespace BrilliantMind\Mkesh\Response;

use DOMElement;
use BrilliantMind\Mkesh\Xml\XmlReader;

/**
 * sptransferresponse v1.2 — returned from a B2C payout (sptransfer) request.
 */
final class SpTransferResponse
{
    public function __construct(
        public readonly string $transactionId,
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
            transactionId: XmlReader::requireText($root, 'transactionid'),
            providerTransactionId: XmlReader::text($root, 'providertransactionid'),
        );
    }
}
