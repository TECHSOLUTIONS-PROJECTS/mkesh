<?php

declare(strict_types=1);

namespace BrilliantMind\Mkesh\Tests;

use PHPUnit\Framework\TestCase;
use BrilliantMind\Mkesh\Config\MkeshConfig;
use BrilliantMind\Mkesh\Request\DebitRequest;
use BrilliantMind\Mkesh\Request\GetTransactionStatusRequest;
use BrilliantMind\Mkesh\Request\SpTransferRequest;
use BrilliantMind\Mkesh\ValueObject\Fri;
use BrilliantMind\Mkesh\ValueObject\Money;

final class RequestXmlTest extends TestCase
{
    private function config(): MkeshConfig
    {
        return new MkeshConfig(
            username: 'MTL',
            password: 'secret',
            serviceProviderFri: 'FRI:pagamKesh/USER',
            transactionPrefix: 'MTL',
            callbackUrl: 'https://partner.example/mkesh/callback',
        );
    }

    /**
     * The element names, order and namespace must match the payload in the
     * provider's integration sheet exactly.
     */
    public function test_debit_request_matches_the_documented_payload(): void
    {
        $xml = (new DebitRequest(
            fromFri: Fri::msisdn('258823040400'),
            amount: Money::of(25),
            externalTransactionId: '000001',
            referenceId: '000001',
        ))->toXml($this->config());

        $doc = new \DOMDocument();
        self::assertTrue($doc->loadXML($xml));

        self::assertSame('debitrequest', $doc->documentElement->localName);
        self::assertSame('http://www.ericsson.com/em/emm/financial/v1_1', $doc->documentElement->namespaceURI);
        self::assertStringContainsString('<fromfri>FRI:258823040400/MSISDN</fromfri>', $xml);
        self::assertStringContainsString('<tofri>FRI:pagamKesh/USER</tofri>', $xml);
        self::assertStringContainsString('<amount><amount>25</amount><currency>MZN</currency></amount>', $xml);
        self::assertStringContainsString('<externaltransactionid>MTL000001</externaltransactionid>', $xml);
        self::assertStringContainsString('<referenceid>MTL000001</referenceid>', $xml);

        self::assertSame(
            ['fromfri', 'tofri', 'amount', 'externaltransactionid', 'referenceid'],
            $this->childNames($doc),
        );
    }

    /**
     * gettransactionstatus looks a debit up by referenceid, so one is always
     * sent — defaulting to the external transaction id as the sheet does.
     */
    public function test_debit_request_defaults_reference_id_to_the_external_id(): void
    {
        $request = new DebitRequest(
            fromFri: Fri::msisdn('258823040400'),
            amount: Money::of(25),
            externalTransactionId: '000001',
        );

        self::assertSame('000001', $request->referenceId());
        self::assertStringContainsString(
            '<referenceid>MTL000001</referenceid>',
            $request->toXml($this->config()),
        );
    }

    /**
     * The documented payload has no <callbackurl>: the aggregator forwards the
     * callback to the endpoint the partner registered with it.
     */
    public function test_debit_request_omits_callback_url_by_default(): void
    {
        $request = new DebitRequest(
            fromFri: Fri::msisdn('258823040400'),
            amount: Money::of(25),
            externalTransactionId: '000001',
            callbackUrl: 'https://partner.example/override',
        );

        self::assertStringNotContainsString('callbackurl', $request->toXml($this->config()));
    }

    public function test_debit_request_sends_callback_url_when_opted_in(): void
    {
        $config = new MkeshConfig(
            username: 'MTL',
            password: 'secret',
            serviceProviderFri: 'FRI:pagamKesh/USER',
            transactionPrefix: 'MTL',
            callbackUrl: 'https://partner.example/mkesh/callback',
            sendCallbackUrl: true,
        );

        $xml = (new DebitRequest(
            fromFri: Fri::msisdn('258823040400'),
            amount: Money::of(25),
            externalTransactionId: '000001',
        ))->toXml($config);

        self::assertStringContainsString(
            '<callbackurl>https://partner.example/mkesh/callback</callbackurl>',
            $xml,
        );
    }

    public function test_sptransfer_request_matches_the_documented_payload(): void
    {
        $xml = SpTransferRequest::payout(
            customerMsisdn: '258823040400',
            amount: Money::of(25),
            providerTransactionId: 'XXXXX',
        )->toXml($this->config());

        $doc = new \DOMDocument();
        self::assertTrue($doc->loadXML($xml));

        self::assertStringContainsString('<sendingfri>FRI:pagamKesh/USER</sendingfri>', $xml);
        self::assertStringContainsString('<receivingfri>FRI:258823040400/MSISDN</receivingfri>', $xml);
        self::assertStringContainsString('<providertransactionid>MTLXXXXX</providertransactionid>', $xml);
        // referenceid defaults to the provider transaction id.
        self::assertStringContainsString('<referenceid>MTLXXXXX</referenceid>', $xml);
        self::assertStringContainsString('serviceprovider/v1_2/backend', $xml);

        self::assertSame(
            ['sendingfri', 'receivingfri', 'amount', 'providertransactionid', 'referenceid'],
            $this->childNames($doc),
        );
    }

    /**
     * The sheet pays out from an MM wallet, not the USER FRI credited on a
     * debit, so the sending account is configured separately.
     */
    public function test_sptransfer_uses_the_dedicated_sending_fri_when_configured(): void
    {
        $config = new MkeshConfig(
            username: 'MTL',
            password: 'secret',
            serviceProviderFri: 'FRI:pagamKesh/USER',
            transactionPrefix: 'MTL',
            spTransferSendingFri: 'FRI:47225552/MM',
        );

        $xml = SpTransferRequest::payout(
            customerMsisdn: '258823040400',
            amount: Money::of(25),
            providerTransactionId: 'XXXXX',
        )->toXml($config);

        self::assertStringContainsString('<sendingfri>FRI:47225552/MM</sendingfri>', $xml);
        // The debit side is unaffected.
        self::assertStringContainsString(
            '<tofri>FRI:pagamKesh/USER</tofri>',
            (new DebitRequest(
                fromFri: Fri::msisdn('258823040400'),
                amount: Money::of(25),
                externalTransactionId: '000001',
            ))->toXml($config),
        );
    }

    public function test_get_transaction_status_request_applies_prefix(): void
    {
        $xml = (new GetTransactionStatusRequest('000001'))->toXml($this->config());

        self::assertStringContainsString('<referenceid>MTL000001</referenceid>', $xml);
        self::assertStringContainsString('financial/v1_3', $xml);
    }

    public function test_special_characters_are_escaped(): void
    {
        $xml = (new DebitRequest(
            fromFri: Fri::msisdn('258823040400'),
            amount: Money::of(25),
            externalTransactionId: '000001',
            fromMessage: 'Pay & save <now>',
        ))->toXml($this->config());

        self::assertStringContainsString('Pay &amp; save &lt;now&gt;', $xml);
    }

    /**
     * @return array<int, string>
     */
    private function childNames(\DOMDocument $doc): array
    {
        $names = [];
        foreach ($doc->documentElement->childNodes as $child) {
            if ($child instanceof \DOMElement) {
                $names[] = $child->localName;
            }
        }

        return $names;
    }
}
