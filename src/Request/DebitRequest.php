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
 * debitrequest v1.1 — the C2B "charge the customer" operation.
 *
 * Money is debited from {@see $fromFri} (the paying customer, a Mobile Money
 * account) and credited to {@see $toFri} (defaults to the service-provider
 * wallet from config).
 *
 * The synchronous response is PENDING: the customer receives an
 * APPROVAL_REQUESTING_PENDING SMS and must approve before the request expires.
 * The outcome then arrives as a debitcompleted callback, or can be recovered
 * with gettransactionstatus using {@see referenceId()}.
 */
final class DebitRequest
{
    public function __construct(
        public readonly Fri $fromFri,
        public readonly Money $amount,
        public readonly string $externalTransactionId,
        public readonly ?Fri $toFri = null,
        /**
         * The id used to look the transaction up later with gettransactionstatus.
         * Defaults to {@see $externalTransactionId} — the integration sheet sends
         * the same value in both elements.
         */
        public readonly ?string $referenceId = null,
        public readonly ?string $fromMessage = null,
        public readonly ?string $toMessage = null,
        public readonly ?string $callbackUrl = null,
    ) {
        if (trim($externalTransactionId) === '') {
            throw new InvalidArgumentException('externalTransactionId is required for a debit request.');
        }
    }

    /**
     * The referenceid actually sent on the wire, before the config prefix is
     * applied — i.e. the external transaction id when none was given.
     */
    public function referenceId(): string
    {
        return $this->referenceId ?? $this->externalTransactionId;
    }

    /**
     * Convenience constructor for the common case: charge a customer MSISDN.
     */
    public static function charge(
        string $customerMsisdn,
        Money $amount,
        string $externalTransactionId,
        ?string $referenceId = null,
    ): self {
        return new self(
            fromFri: Fri::msisdn($customerMsisdn),
            amount: $amount,
            externalTransactionId: $externalTransactionId,
            referenceId: $referenceId,
        );
    }

    public function toXml(MkeshConfig $config): string
    {
        $writer = new XmlWriter(Namespaces::FINANCIAL_V1_1, 'debitrequest');

        $amount = $this->amount;

        // Element order follows the payload in the integration sheet.
        return $writer
            ->child('fromfri', (string) $this->fromFri)
            ->child('tofri', (string) ($this->toFri ?? $config->serviceProviderFri))
            ->element('amount', static function (DOMElement $el) use ($amount): void {
                $el->appendChild($el->ownerDocument->createElement('amount', $amount->amount));
                $el->appendChild($el->ownerDocument->createElement('currency', $amount->currency));
            })
            ->child('externaltransactionid', $config->applyPrefix($this->externalTransactionId))
            ->child('referenceid', $config->applyPrefix($this->referenceId()))
            ->child('frommessage', $this->fromMessage)
            ->child('tomessage', $this->toMessage)
            // Not part of the documented payload — the aggregator forwards the
            // callback to the endpoint registered by the partner. Opt in via
            // MkeshConfig::$sendCallbackUrl if your instance accepts it.
            ->child('callbackurl', $config->sendCallbackUrl ? ($this->callbackUrl ?? $config->callbackUrl) : null)
            ->toXml();
    }
}
