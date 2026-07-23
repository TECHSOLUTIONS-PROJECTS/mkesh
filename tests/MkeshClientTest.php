<?php

declare(strict_types=1);

namespace BrilliantMind\Mkesh\Tests;

use GuzzleHttp\Psr7\HttpFactory;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\RequestInterface;
use BrilliantMind\Mkesh\Config\MkeshConfig;
use BrilliantMind\Mkesh\Exception\ErrorResponseException;
use BrilliantMind\Mkesh\MkeshClient;
use BrilliantMind\Mkesh\Request\DebitRequest;
use BrilliantMind\Mkesh\Tests\Support\FakeHttpClient;
use BrilliantMind\Mkesh\ValueObject\Fri;
use BrilliantMind\Mkesh\ValueObject\Money;

final class MkeshClientTest extends TestCase
{
    private function config(): MkeshConfig
    {
        return new MkeshConfig(
            username: 'user',
            password: 'secret',
            serviceProviderFri: 'FRI:pagamKesh/USER',
            transactionPrefix: 'TBD',
        );
    }

    public function test_debit_sends_basic_auth_and_xml_and_parses_response(): void
    {
        $body = '<?xml version="1.0" encoding="UTF-8"?>'
            . '<ns0:debitresponse xmlns:ns0="http://www.ericsson.com/em/emm/financial/v1_1">'
            . '<transactionid>3171312</transactionid><status>PENDING</status><approvalid>470390</approvalid>'
            . '</ns0:debitresponse>';

        $http = new FakeHttpClient(new Response(200, [], $body));
        $factory = new HttpFactory();
        $client = new MkeshClient($this->config(), $http, $factory, $factory);

        $response = $client->debit(new DebitRequest(
            fromFri: Fri::msisdn('258823040400'),
            amount: Money::of(25),
            externalTransactionId: '000001',
        ));

        self::assertSame('3171312', $response->transactionId);
        self::assertTrue($response->isPending());

        $captured = $http->lastRequest;
        self::assertInstanceOf(RequestInterface::class, $captured);
        self::assertSame('POST', $captured->getMethod());
        self::assertStringContainsString('/DebitServlet/DebitSvlt', (string) $captured->getUri());
        self::assertSame('Basic ' . base64_encode('user:secret'), $captured->getHeaderLine('Authorization'));
        self::assertStringContainsString('text/xml', $captured->getHeaderLine('Content-Type'));
        self::assertStringContainsString('<fromfri>FRI:258823040400/MSISDN</fromfri>', (string) $captured->getBody());
    }

    public function test_error_response_with_non_2xx_status_is_surfaced(): void
    {
        $body = '<?xml version="1.0" encoding="UTF-8"?>'
            . '<ns0:errorResponse xmlns:ns0="http://www.ericsson.com/lwac" errorcode="REFERENCE_ID_ALREADY_IN_USE"/>';

        $http = new FakeHttpClient(new Response(400, [], $body));
        $factory = new HttpFactory();
        $client = new MkeshClient($this->config(), $http, $factory, $factory);

        $this->expectException(ErrorResponseException::class);
        $client->debit(new DebitRequest(
            fromFri: Fri::msisdn('258823040400'),
            amount: Money::of(25),
            externalTransactionId: '000001',
        ));
    }
}
