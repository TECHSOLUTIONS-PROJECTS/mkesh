<?php

declare(strict_types=1);

namespace BrilliantMind\Mkesh\Tests;

use PHPUnit\Framework\TestCase;
use BrilliantMind\Mkesh\Callback\CallbackResponse;
use BrilliantMind\Mkesh\Config\MkeshConfig;
use BrilliantMind\Mkesh\Enum\TransactionStatus;
use BrilliantMind\Mkesh\Exception\ErrorResponseException;
use BrilliantMind\Mkesh\MkeshClient;
use BrilliantMind\Mkesh\Request\DebitRequest;
use BrilliantMind\Mkesh\Tests\Support\FakeHttpClient;
use BrilliantMind\Mkesh\ValueObject\Fri;
use BrilliantMind\Mkesh\ValueObject\Money;

/**
 * Parses the verbatim payloads from the provider's "Integration MTL_EWP
 * Agregator" sheet, so a change in the SDK that breaks the documented wire
 * format fails here.
 */
final class IntegrationSheetTest extends TestCase
{
    private function config(): MkeshConfig
    {
        return new MkeshConfig(
            username: 'MTL',
            password: 'secret',
            serviceProviderFri: 'FRI:pagamKesh/USER',
            transactionPrefix: 'MTL',
        );
    }

    private function client(string $responseBody, int $status = 200): MkeshClient
    {
        $http = new FakeHttpClient(new \GuzzleHttp\Psr7\Response($status, [], $responseBody));
        $factory = new \GuzzleHttp\Psr7\HttpFactory();

        return new MkeshClient($this->config(), $http, $factory, $factory);
    }

    public function test_parses_the_documented_debit_response(): void
    {
        $client = $this->client(<<<'XML'
            <?xml version="1.0" encoding="UTF-8"?>
            <ns0:debitresponse xmlns:ns0="http://www.ericsson.com/em/emm/financial/v1_1">
            <transactionid>3171312</transactionid>
            <status>PENDING</status>
            <approvalid>470390</approvalid>
            </ns0:debitresponse>
            XML);

        $response = $client->debit(new DebitRequest(
            fromFri: Fri::msisdn('258823040400'),
            amount: Money::of(25),
            externalTransactionId: '000001',
        ));

        self::assertSame('3171312', $response->transactionId);
        self::assertSame(TransactionStatus::PENDING, $response->status);
        self::assertSame('470390', $response->approvalId);
        self::assertTrue($response->isPending());
    }

    public function test_parses_the_documented_success_status_response(): void
    {
        $client = $this->client(<<<'XML'
            <?xml version="1.0" encoding="UTF-8"?>
            <ns0:gettransactionstatusresponse xmlns:ns0="http://www.ericsson.com/em/emm/financial/v1_3">
            <financialtransactionid>3171312</financialtransactionid>
            <status>SUCCESSFUL</status>
            <providertransactionid>MTL000001</providertransactionid>
            </ns0:gettransactionstatusresponse>
            XML);

        $status = $client->getTransactionStatus('000001');

        self::assertSame('3171312', $status->financialTransactionId);
        self::assertSame('MTL000001', $status->providerTransactionId);
        self::assertTrue($status->isSuccessful());
        self::assertTrue($status->status->isSettled());
    }

    /** The FAILED variant in the sheet carries no providertransactionid. */
    public function test_parses_the_documented_failed_status_response(): void
    {
        $client = $this->client(
            '<?xml version="1.0" encoding="UTF-8"?><ns0:gettransactionstatusresponse'
            . ' xmlns:ns0="http://www.ericsson.com/em/emm/financial/v1_3">'
            . '<financialtransactionid>3221328</financialtransactionid><status>FAILED</status>'
            . '</ns0:gettransactionstatusresponse>',
        );

        $status = $client->getTransactionStatus('000001');

        self::assertSame('3221328', $status->financialTransactionId);
        self::assertNull($status->providerTransactionId);
        self::assertTrue($status->isFailed());
    }

    public function test_parses_the_documented_sptransfer_response(): void
    {
        $client = $this->client(<<<'XML'
            <?xml version="1.0" encoding="UTF-8"?>
            <ns0:sptransferresponse xmlns:ns0="http://www.ericsson.com/em/emm/serviceprovider/v1_2/backend">
            <transactionid>3282002</transactionid>
            <providertransactionid>MTL-XXXXXX</providertransactionid>
            </ns0:sptransferresponse>
            XML);

        $response = $client->transfer(\BrilliantMind\Mkesh\Request\SpTransferRequest::payout(
            customerMsisdn: '258823040400',
            amount: Money::of(25),
            providerTransactionId: 'XXXXXX',
        ));

        self::assertSame('3282002', $response->transactionId);
        self::assertSame('MTL-XXXXXX', $response->providerTransactionId);
    }

    public function test_parses_the_documented_debit_completed_callback(): void
    {
        $client = $this->client('');

        $callback = $client->parseDebitCompleted(<<<'XML'
            <?xml version="1.0" encoding="UTF-8"?>
            <ns0:debitcompletedrequest xmlns:ns0="http://www.ericsson.com/em/emm/callback/v1_2">
            <transactionid>3171312</transactionid>
            <externaltransactionid>MTL000001</externaltransactionid>
            <receiverinfo>
                   <fri>FRI:1360073/MM</fri>
                   <msisdn>8230X04XX</msisdn>
                   <language>en</language>
            </receiverinfo>
            <status>SUCCESSFUL</status>
            <communicationchannel>http-sp</communicationchannel>
            <referenceid>MTL000001</referenceid>
            </ns0:debitcompletedrequest>
            XML);

        self::assertSame('3171312', $callback->transactionId);
        self::assertSame('MTL000001', $callback->externalTransactionId);
        self::assertSame('MTL000001', $callback->referenceId);
        self::assertSame('http-sp', $callback->communicationChannel);
        self::assertSame('FRI:1360073/MM', $callback->receiver->fri);
        self::assertSame('8230X04XX', $callback->receiver->msisdn);
        self::assertSame('en', $callback->receiver->language);
        self::assertTrue($callback->isSuccessful());
    }

    /** The sheet's documented acknowledgement for the callback. */
    public function test_callback_acknowledgement_matches_the_documented_body(): void
    {
        self::assertSame(
            '<?xml version="1.0" encoding="utf-8"?><ResponseCode>SUCCESS</ResponseCode>',
            CallbackResponse::success()->toXml(),
        );

        $client = $this->client('');
        self::assertSame(
            CallbackResponse::success()->toXml(),
            $client->acknowledgeCallback()->toXml(),
        );
    }

    /**
     * @return array<string, array{0: string, 1: array<string, string>}>
     */
    public static function documentedErrors(): array
    {
        return [
            'transaction not found' => [
                '<?xml version="1.0" encoding="UTF-8"?><ns0:errorResponse'
                . ' xmlns:ns0="http://www.ericsson.com/lwac" errorcode="TRANSACTION_NOT_FOUND"/>',
                [],
            ],
            'duplicate reference' => [
                '<?xml version="1.0" encoding="UTF-8"?><ns0:errorResponse'
                . ' xmlns:ns0="http://www.ericsson.com/lwac" errorcode="REFERENCE_ID_ALREADY_IN_USE"/>',
                [],
            ],
            'account not found' => [
                '<?xml version="1.0" encoding="UTF-8"?><ns0:errorResponse'
                . ' xmlns:ns0="http://www.ericsson.com/lwac" errorcode="ACCOUNTHOLDER_WITH_FRI_NOT_FOUND">'
                . '<arguments name="fri" value="FRI:258823040420/MSISDN"/></ns0:errorResponse>',
                ['fri' => 'FRI:258823040420/MSISDN'],
            ],
        ];
    }

    /**
     * @param array<string, string> $expectedArguments
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('documentedErrors')]
    public function test_documented_error_responses_become_exceptions(string $xml, array $expectedArguments): void
    {
        $client = $this->client($xml);

        try {
            $client->getTransactionStatus('000001');
            self::fail('Expected an ErrorResponseException.');
        } catch (ErrorResponseException $e) {
            self::assertNotSame('UNKNOWN_ERROR', $e->getErrorCode());
            self::assertNotNull($e->getDescription(), 'Error code should be in the catalogue.');
            self::assertSame($expectedArguments, $e->getArguments());
            self::assertTrue($e->is($e->getErrorCode()));
            self::assertFalse($e->is('SOME_OTHER_CODE'));
        }
    }

    public function test_transaction_id_generator_applies_the_mandatory_prefix(): void
    {
        $config = $this->config();

        self::assertSame('MTL000001', $config->newTransactionId('000001'));
        // Already-prefixed ids are left alone (applyPrefix is idempotent).
        self::assertSame('MTL000001', $config->newTransactionId('MTL000001'));

        $generated = $config->newTransactionId();
        self::assertStringStartsWith('MTL', $generated);
        self::assertNotSame($generated, $config->newTransactionId());
    }
}
