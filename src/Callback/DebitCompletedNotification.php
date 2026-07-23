<?php

declare(strict_types=1);

namespace TechSolutions\Mkesh\Callback;

use DOMElement;
use TechSolutions\Mkesh\Enum\TransactionStatus;
use TechSolutions\Mkesh\ValueObject\ReceiverInfo;
use TechSolutions\Mkesh\Xml\XmlReader;

/**
 * debitcompletedrequest v1.2 — the asynchronous callback the aggregator POSTs
 * to the partner's callback URL once a previously requested debit (C2B) has
 * settled. Parse the raw request body with {@see fromXml()} and reply with an
 * empty 200 response to acknowledge it.
 */
final class DebitCompletedNotification
{
    public function __construct(
        public readonly string $transactionId,
        public readonly string $externalTransactionId,
        public readonly TransactionStatus $status,
        public readonly ReceiverInfo $receiver,
        public readonly ?string $communicationChannel = null,
        public readonly ?string $referenceId = null,
    ) {
    }

    /**
     * Parse the raw callback request body.
     */
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
            transactionId: XmlReader::requireText($root, 'transactionid'),
            externalTransactionId: XmlReader::requireText($root, 'externaltransactionid'),
            status: TransactionStatus::fromWire(XmlReader::text($root, 'status')),
            receiver: $receiverInfo,
            communicationChannel: XmlReader::text($root, 'communicationchannel'),
            referenceId: XmlReader::text($root, 'referenceid'),
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
