<?php

declare(strict_types=1);

namespace BrilliantMind\Mkesh\Tests;

use PHPUnit\Framework\TestCase;
use BrilliantMind\Mkesh\Callback\DebitCompletedNotification;
use BrilliantMind\Mkesh\Callback\InitiateTransferCompletedNotification;
use BrilliantMind\Mkesh\Enum\TransactionStatus;
use BrilliantMind\Mkesh\Exception\ErrorResponseException;
use BrilliantMind\Mkesh\Exception\TransportException;
use BrilliantMind\Mkesh\Response\DebitResponse;
use BrilliantMind\Mkesh\Response\SpTransferResponse;
use BrilliantMind\Mkesh\Response\TransactionStatusResponse;

final class ResponseParsingTest extends TestCase
{
    public function test_debit_response_parses_pending_with_approval_id(): void
    {
        $xml = '<?xml version="1.0" encoding="UTF-8"?>'
            . '<ns0:debitresponse xmlns:ns0="http://www.ericsson.com/em/emm/financial/v1_1">'
            . '<transactionid>3171312</transactionid><status>PENDING</status><approvalid>470390</approvalid>'
            . '</ns0:debitresponse>';

        $response = DebitResponse::fromXml($xml);

        self::assertSame('3171312', $response->transactionId);
        self::assertSame(TransactionStatus::PENDING, $response->status);
        self::assertSame('470390', $response->approvalId);
        self::assertTrue($response->isPending());
    }

    public function test_sptransfer_response_parses(): void
    {
        $xml = '<?xml version="1.0" encoding="UTF-8"?>'
            . '<ns0:sptransferresponse xmlns:ns0="http://www.ericsson.com/em/emm/serviceprovider/v1_2/backend">'
            . '<transactionid>3282002</transactionid><providertransactionid>paga-1</providertransactionid>'
            . '</ns0:sptransferresponse>';

        $response = SpTransferResponse::fromXml($xml);

        self::assertSame('3282002', $response->transactionId);
        self::assertSame('paga-1', $response->providerTransactionId);
    }

    public function test_transaction_status_response_parses_failure(): void
    {
        $xml = '<?xml version="1.0" encoding="UTF-8"?>'
            . '<ns0:gettransactionstatusresponse xmlns:ns0="http://www.ericsson.com/em/emm/financial/v1_3">'
            . '<financialtransactionid>3221328</financialtransactionid><status>FAILED</status>'
            . '</ns0:gettransactionstatusresponse>';

        $response = TransactionStatusResponse::fromXml($xml);

        self::assertSame('3221328', $response->financialTransactionId);
        self::assertTrue($response->isFailed());
    }

    public function test_error_response_throws_with_code_and_arguments(): void
    {
        $xml = '<?xml version="1.0" encoding="UTF-8"?>'
            . '<ns0:errorResponse xmlns:ns0="http://www.ericsson.com/lwac" errorcode="ACCOUNTHOLDER_WITH_FRI_NOT_FOUND">'
            . '<arguments name="fri" value="FRI:258823040420/MSISDN"/>'
            . '</ns0:errorResponse>';

        try {
            DebitResponse::fromXml($xml);
            self::fail('Expected ErrorResponseException.');
        } catch (ErrorResponseException $e) {
            self::assertSame('ACCOUNTHOLDER_WITH_FRI_NOT_FOUND', $e->getErrorCode());
            self::assertSame('FRI:258823040420/MSISDN', $e->getArgument('fri'));
        }
    }

    public function test_empty_body_is_transport_error(): void
    {
        $this->expectException(TransportException::class);
        DebitResponse::fromXml('   ');
    }

    public function test_debit_completed_callback_parses(): void
    {
        $xml = '<?xml version="1.0" encoding="UTF-8"?>'
            . '<ns0:debitcompletedrequest xmlns:ns0="http://www.ericsson.com/em/emm/callback/v1_2">'
            . '<transactionid>3171312</transactionid>'
            . '<externaltransactionid>TBD000001</externaltransactionid>'
            . '<receiverinfo><fri>FRI:1360073/MM</fri><msisdn>8230X04XX</msisdn><language>en</language></receiverinfo>'
            . '<status>SUCCESSFUL</status>'
            . '<communicationchannel>http-sp</communicationchannel>'
            . '<referenceid>TBD000001</referenceid>'
            . '</ns0:debitcompletedrequest>';

        $callback = DebitCompletedNotification::fromXml($xml);

        self::assertSame('3171312', $callback->transactionId);
        self::assertSame('TBD000001', $callback->externalTransactionId);
        self::assertTrue($callback->isSuccessful());
        self::assertSame('FRI:1360073/MM', $callback->receiver->fri);
        self::assertSame('8230X04XX', $callback->receiver->msisdn);
        self::assertSame('en', $callback->receiver->language);
        self::assertSame('http-sp', $callback->communicationChannel);
    }

    public function test_initiate_transfer_completed_callback_parses(): void
    {
        $xml = '<?xml version="1.0" encoding="UTF-8"?>'
            . '<ns0:initiatetransfercompletedrequest xmlns:ns0="http://www.ericsson.com/em/emm/callback/v1_0">'
            . '<financialtransactionid>3282002</financialtransactionid>'
            . '<externaltransactionid>TBDpaga-1</externaltransactionid>'
            . '<receiverinfo><fri>FRI:1360073/MM</fri><msisdn>8230X04XX</msisdn></receiverinfo>'
            . '<status>SUCCESSFUL</status>'
            . '<communicationchannel>http-sp</communicationchannel>'
            . '</ns0:initiatetransfercompletedrequest>';

        $callback = InitiateTransferCompletedNotification::fromXml($xml);

        self::assertSame('3282002', $callback->financialTransactionId);
        self::assertSame('TBDpaga-1', $callback->externalTransactionId);
        self::assertTrue($callback->isSuccessful());
        self::assertSame('FRI:1360073/MM', $callback->receiver->fri);
    }
}
