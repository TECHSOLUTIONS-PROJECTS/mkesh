<?php

declare(strict_types=1);

namespace BrilliantMind\Mkesh\Request;

use BrilliantMind\Mkesh\Config\MkeshConfig;
use BrilliantMind\Mkesh\Exception\InvalidArgumentException;
use BrilliantMind\Mkesh\Xml\Namespaces;
use BrilliantMind\Mkesh\Xml\XmlWriter;

/**
 * gettransactionstatusrequest v1.3 — used to recover the outcome of an earlier
 * operation by its referenceid when no response/callback was received.
 */
final class GetTransactionStatusRequest
{
    public function __construct(
        public readonly string $referenceId,
    ) {
        if (trim($referenceId) === '') {
            throw new InvalidArgumentException('referenceId is required to query transaction status.');
        }
    }

    public function toXml(MkeshConfig $config): string
    {
        return (new XmlWriter(Namespaces::FINANCIAL_V1_3, 'gettransactionstatusrequest'))
            ->child('referenceid', $config->applyPrefix($this->referenceId))
            ->toXml();
    }
}
