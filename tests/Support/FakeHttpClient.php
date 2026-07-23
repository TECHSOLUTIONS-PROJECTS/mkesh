<?php

declare(strict_types=1);

namespace BrilliantMind\Mkesh\Tests\Support;

use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

/**
 * Minimal PSR-18 client that returns a canned response and records the last
 * request it was asked to send.
 */
final class FakeHttpClient implements ClientInterface
{
    public ?RequestInterface $lastRequest = null;

    public function __construct(private readonly ResponseInterface $response)
    {
    }

    public function sendRequest(RequestInterface $request): ResponseInterface
    {
        $this->lastRequest = $request;

        return $this->response;
    }
}
