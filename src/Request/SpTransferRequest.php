<?php

declare(strict_types=1);

namespace BrilliantMind\Mkesh\Request;

use DOMElement;
use BrilliantMind\Mkesh\Config\MkeshConfig;
use BrilliantMind\Mkesh\Exception\InvalidArgumentException;
use BrilliantMind\Mkesh\ValueObject\Fri;
use BrilliantMind\Mkesh\ValueObject\Money;
use BrilliantMind\Mkesh\Xml\Namespaces;
use BrilliantMind\Mkesh\Xml\XmlWriter;

/**
 * sptransferrequest v1.2 — the B2C "pay out to the customer" operation.
 *
 * Money is sent from {@see $sendingFri} (defaults to
 * {@see \BrilliantMind\Mkesh\Config\MkeshConfig::$spTransferSendingFri}) to
 * {@see $receivingFri} (the customer).
 */
final class SpTransferRequest
{
    public function __construct(
        public readonly Fri $receivingFri,
        public readonly Money $amount,
        public readonly string $providerTransactionId,
        public readonly ?Fri $sendingFri = null,
        /** Defaults to {@see $providerTransactionId}, as in the integration sheet. */
        public readonly ?string $referenceId = null,
        public readonly ?string $name = null,
        public readonly ?string $senderNote = null,
        public readonly ?string $receiverMessage = null,
    ) {
        if (trim($providerTransactionId) === '') {
            throw new InvalidArgumentException('providerTransactionId is required for an sptransfer request.');
        }
    }

    /**
     * The referenceid actually sent on the wire, before the config prefix is
     * applied — i.e. the provider transaction id when none was given.
     */
    public function referenceId(): string
    {
        return $this->referenceId ?? $this->providerTransactionId;
    }

    /**
     * Convenience constructor: pay out to a customer MSISDN.
     */
    public static function payout(
        string $customerMsisdn,
        Money $amount,
        string $providerTransactionId,
        ?string $referenceId = null,
    ): self {
        return new self(
            receivingFri: Fri::msisdn($customerMsisdn),
            amount: $amount,
            providerTransactionId: $providerTransactionId,
            referenceId: $referenceId,
        );
    }

    public function toXml(MkeshConfig $config): string
    {
        $writer = new XmlWriter(Namespaces::SERVICEPROVIDER_V1_2, 'sptransferrequest', 'ns2');

        $amount = $this->amount;

        // Element order follows the payload in the integration sheet.
        return $writer
            ->child('sendingfri', (string) ($this->sendingFri ?? $config->spTransferSendingFri))
            ->child('receivingfri', (string) $this->receivingFri)
            ->element('amount', static function (DOMElement $el) use ($amount): void {
                $el->appendChild($el->ownerDocument->createElement('amount', $amount->amount));
                $el->appendChild($el->ownerDocument->createElement('currency', $amount->currency));
            })
            ->child('providertransactionid', $config->applyPrefix($this->providerTransactionId))
            ->child('referenceid', $config->applyPrefix($this->referenceId()))
            ->child('name', $this->name)
            ->child('sendernote', $this->senderNote)
            ->child('receivermessage', $this->receiverMessage)
            ->toXml();
    }
}
