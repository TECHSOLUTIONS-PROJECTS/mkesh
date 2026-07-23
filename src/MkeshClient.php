<?php

declare(strict_types=1);

namespace TechSolutions\Mkesh;

use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\Psr7\HttpFactory;
use Psr\Http\Client\ClientExceptionInterface;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\StreamFactoryInterface;
use TechSolutions\Mkesh\Callback\CallbackResponse;
use TechSolutions\Mkesh\Callback\DebitCompletedNotification;
use TechSolutions\Mkesh\Callback\InitiateTransferCompletedNotification;
use TechSolutions\Mkesh\Config\MkeshConfig;
use TechSolutions\Mkesh\Exception\TransportException;
use TechSolutions\Mkesh\Request\DebitRequest;
use TechSolutions\Mkesh\Request\GetTransactionStatusRequest;
use TechSolutions\Mkesh\Request\SpTransferRequest;
use TechSolutions\Mkesh\Response\DebitResponse;
use TechSolutions\Mkesh\Response\SpTransferResponse;
use TechSolutions\Mkesh\Response\TransactionStatusResponse;

/**
 * Entry point for the MKESH / EWP aggregator integration.
 *
 * Build one with {@see create()} (which wires a Guzzle PSR-18 client honouring
 * the SSL/timeout settings in config) or inject your own PSR-18 client and
 * PSR-17 factories through the constructor.
 */
final class MkeshClient
{
    public function __construct(
        private readonly MkeshConfig $config,
        private readonly ClientInterface $httpClient,
        private readonly RequestFactoryInterface $requestFactory,
        private readonly StreamFactoryInterface $streamFactory,
    ) {
    }

    /**
     * Build a client backed by Guzzle, applying the SSL and timeout settings
     * from the configuration.
     */
    public static function create(MkeshConfig $config): self
    {
        $verify = $config->verifySsl ? ($config->sslCaBundle ?? true) : false;

        $guzzle = new GuzzleClient([
            'verify' => $verify,
            'timeout' => $config->timeout,
            'http_errors' => false,
        ]);

        $factory = new HttpFactory();

        return new self($config, $guzzle, $factory, $factory);
    }

    public function config(): MkeshConfig
    {
        return $this->config;
    }

    /**
     * debitrequest (C2B) — request a debit from a customer's wallet. The
     * synchronous response is usually PENDING; the final outcome arrives via
     * the debitcompleted callback.
     */
    public function debit(DebitRequest $request): DebitResponse
    {
        $body = $this->send($this->config->debitUrl(), $request->toXml($this->config));

        return DebitResponse::fromXml($body);
    }

    /**
     * sptransfer (B2C) — pay out from the service-provider wallet to a customer.
     */
    public function transfer(SpTransferRequest $request): SpTransferResponse
    {
        $body = $this->send($this->config->spTransferUrl(), $request->toXml($this->config));

        return SpTransferResponse::fromXml($body);
    }

    /**
     * gettransactionstatus — recover the outcome of an operation by its
     * referenceid (the SDK applies the configured transaction prefix).
     */
    public function getTransactionStatus(string|GetTransactionStatusRequest $reference): TransactionStatusResponse
    {
        $request = $reference instanceof GetTransactionStatusRequest
            ? $reference
            : new GetTransactionStatusRequest($reference);

        $body = $this->send($this->config->transactionStatusUrl(), $request->toXml($this->config));

        return TransactionStatusResponse::fromXml($body);
    }

    /**
     * Parse an incoming debitcompleted callback body into a typed object.
     * This does not perform any network call.
     */
    public function parseDebitCompleted(string $xml): DebitCompletedNotification
    {
        return DebitCompletedNotification::fromXml($xml);
    }

    /**
     * Parse an incoming initiatetransfercompleted callback body (the B2C
     * completion notification) into a typed object. No network call is made.
     */
    public function parseInitiateTransferCompleted(string $xml): InitiateTransferCompletedNotification
    {
        return InitiateTransferCompletedNotification::fromXml($xml);
    }

    /**
     * The body to return from your callback endpoint to acknowledge a
     * debitcompleted notification. An empty 200 is not enough — the aggregator
     * expects <ResponseCode>SUCCESS</ResponseCode> and retries without it.
     */
    public function acknowledgeCallback(): CallbackResponse
    {
        return CallbackResponse::success();
    }

    /**
     * POST an XML body to the given URL and return the raw response body.
     *
     * @throws TransportException on transport-level failures
     */
    private function send(string $url, string $xml): string
    {
        $request = $this->requestFactory->createRequest('POST', $url)
            ->withHeader('Authorization', $this->authorizationHeader())
            ->withHeader('Content-Type', 'text/xml; charset=UTF-8')
            ->withHeader('Accept', 'text/xml, application/xml')
            ->withBody($this->streamFactory->createStream($xml));

        try {
            $response = $this->httpClient->sendRequest($request);
        } catch (ClientExceptionInterface $e) {
            throw new TransportException(
                sprintf('HTTP request to MKESH failed: %s', $e->getMessage()),
                (int) $e->getCode(),
                $e,
            );
        }

        $body = (string) $response->getBody();
        $status = $response->getStatusCode();

        // A non-2xx with no body is a pure transport failure. Otherwise the body
        // is returned as-is: EWP signals business errors with an <errorResponse>
        // envelope which the response parsers turn into an ErrorResponseException
        // (XmlReader::rootOf), and an empty 2xx body is a valid acknowledgement
        // for callbacks/operations that respond with nothing.
        if (trim($body) === '' && ($status < 200 || $status >= 300)) {
            throw new TransportException(sprintf('MKESH returned HTTP %d with an empty body.', $status));
        }

        return $body;
    }

    private function authorizationHeader(): string
    {
        return 'Basic ' . base64_encode($this->config->username . ':' . $this->config->password);
    }
}
