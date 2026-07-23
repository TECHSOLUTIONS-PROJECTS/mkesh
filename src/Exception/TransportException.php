<?php

declare(strict_types=1);

namespace BrilliantMind\Mkesh\Exception;

/**
 * Thrown when the HTTP request to the aggregator fails at the transport level
 * (connection refused, TLS error, timeout, etc.) or returns an unparsable body.
 */
class TransportException extends \RuntimeException implements MkeshException
{
}
