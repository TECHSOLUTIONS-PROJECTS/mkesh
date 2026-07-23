<?php

declare(strict_types=1);

namespace BrilliantMind\Mkesh\Xml;

/**
 * XML namespaces used across the EWP "XML over HTTP" operations.
 */
final class Namespaces
{
    /** debitrequest / debitresponse. */
    public const FINANCIAL_V1_1 = 'http://www.ericsson.com/em/emm/financial/v1_1';

    /** gettransactionstatusrequest / -response. */
    public const FINANCIAL_V1_3 = 'http://www.ericsson.com/em/emm/financial/v1_3';

    /** sptransferrequest / sptransferresponse. */
    public const SERVICEPROVIDER_V1_2 = 'http://www.ericsson.com/em/emm/serviceprovider/v1_2/backend';

    /** debitcompletedrequest (outgoing callback to the partner). */
    public const CALLBACK_V1_2 = 'http://www.ericsson.com/em/emm/callback/v1_2';

    /** errorResponse envelope. */
    public const LWAC = 'http://www.ericsson.com/lwac';

    private function __construct()
    {
    }
}
