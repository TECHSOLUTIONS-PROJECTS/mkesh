<?php

declare(strict_types=1);

namespace TechSolutions\Mkesh\Request;

use TechSolutions\Mkesh\Config\MkeshConfig;
use TechSolutions\Mkesh\Exception\InvalidArgumentException;
use TechSolutions\Mkesh\Xml\Namespaces;
use TechSolutions\Mkesh\Xml\XmlWriter;

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
