<?php

declare(strict_types=1);

namespace TechSolutions\Mkesh\Callback;

use DOMElement;
use TechSolutions\Mkesh\Enum\TransactionStatus;
use TechSolutions\Mkesh\ValueObject\ReceiverInfo;
use TechSolutions\Mkesh\Xml\XmlReader;

/**
 * initiatetransfercompletedrequest v1.0 — the asynchronous callback the
 * aggregator POSTs to the partner once a previously requested transfer (B2C)
 * has completed. Parse the raw request body with {@see fromXml()} and reply
 * with an empty 200 response to acknowledge it.
 */
final class InitiateTransferCompletedNotification
{
    public function __construct(
        public readonly string $financialTransactionId,
        public readonly TransactionStatus $status,
        public readonly ReceiverInfo $receiver,
        public readonly ?string $externalTransactionId = null,
        public readonly ?string $communicationChannel = null,
    ) {
    }

    public static function fromXml(string $xml): self
    {
        $root = XmlReader::rootOf($xml);

        $receiverInfo = new ReceiverInfo();
        $receiverNodes = $root->getElementsByTagNameNS('*', 'receiverinfo');
        if ($receiverNodes->item(0) instanceof DOMElement) {
            $node = $receiverNodes->item(0);
            $receiverInfo = new ReceiverInfo(
                fri: XmlReader::text($node, 'fri'),
                msisdn: XmlReader::text($node, 'msisdn'),
                language: XmlReader::text($node, 'language'),
            );
        }

        return new self(
            financialTransactionId: XmlReader::requireText($root, 'financialtransactionid'),
            status: TransactionStatus::fromWire(XmlReader::text($root, 'status')),
            receiver: $receiverInfo,
            externalTransactionId: XmlReader::text($root, 'externaltransactionid'),
            communicationChannel: XmlReader::text($root, 'communicationchannel'),
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
