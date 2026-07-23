<?php

declare(strict_types=1);

return [
    /*
    |--------------------------------------------------------------------------
    | Credentials (HTTP Basic auth)
    |--------------------------------------------------------------------------
    */
    'username' => env('MKESH_USERNAME', ''),
    'password' => env('MKESH_PASSWORD', ''),

    /*
    |--------------------------------------------------------------------------
    | Service provider wallet FRI
    |--------------------------------------------------------------------------
    | The FRI credited when you debit a customer (C2B), e.g. "FRI:pagamKesh/USER".
    */
    'service_provider_fri' => env('MKESH_SP_FRI', ''),

    /*
    |--------------------------------------------------------------------------
    | B2C sending wallet FRI
    |--------------------------------------------------------------------------
    | The wallet money leaves from on an sptransfer payout, e.g.
    | "FRI:47225552/MM". The integration sheet uses a different FRI here than
    | the debit credit account. Leave empty to reuse service_provider_fri.
    */
    'sp_transfer_sending_fri' => env('MKESH_SP_TRANSFER_FRI'),

    /*
    |--------------------------------------------------------------------------
    | Aggregator host
    |--------------------------------------------------------------------------
    */
    'base_url' => env('MKESH_BASE_URL', 'https://41.220.193.151'),

    'default_currency' => env('MKESH_CURRENCY', 'MZN'),

    /*
    |--------------------------------------------------------------------------
    | Transaction id prefix
    |--------------------------------------------------------------------------
    | The aggregator identifies the service provider by the prefix on the ids,
    | so every externaltransactionid / providertransactionid / referenceid must
    | start with the partner token the provider assigns you during onboarding.
    | "ACME" below is only a placeholder — never invent your own value.
    |
    | Set it once here and pass bare ids everywhere else; the SDK prepends it,
    | idempotently:
    |
    |   $config->applyPrefix('000001')       // "ACME000001"
    |   $config->applyPrefix('ACME000001')   // "ACME000001" — not doubled
    |   $config->newTransactionId()          // "ACME9F2C4A1B77E30D55"
    |   $config->newTransactionId('ORD-1234')// "ACMEORD-1234"
    |
    | Leave it empty if your aggregator instance does not require a prefix: the
    | SDK then passes ids through untouched rather than inventing one.
    */
    'transaction_prefix' => env('MKESH_TRANSACTION_PREFIX'),

    /*
    |--------------------------------------------------------------------------
    | Debit callback URL
    |--------------------------------------------------------------------------
    | The endpoint the aggregator POSTs debitcompletedrequest to. You must give
    | this URL to the provider — it is registered on their side, not sent with
    | each request, so this entry is here for reference and for building links.
    |
    | Set send_callback_url=true only if your aggregator instance accepts a
    | per-request <callbackurl> override; the documented payload has no such
    | element and an unexpected one can fail schema validation.
    */
    'callback_url' => env('MKESH_CALLBACK_URL'),
    'send_callback_url' => env('MKESH_SEND_CALLBACK_URL', false),

    /*
    |--------------------------------------------------------------------------
    | TLS verification
    |--------------------------------------------------------------------------
    | The aggregator is reached over HTTPS on an IP with a self-signed cert.
    | Keep verification ON in production with a proper CA bundle; you may need
    | to disable it (MKESH_VERIFY_SSL=false) against the sandbox host.
    */
    'verify_ssl' => env('MKESH_VERIFY_SSL', true),
    'ssl_ca_bundle' => env('MKESH_SSL_CA_BUNDLE'),

    'timeout' => env('MKESH_TIMEOUT', 30.0),

    /*
    |--------------------------------------------------------------------------
    | Endpoint paths (override only if your aggregator differs)
    |--------------------------------------------------------------------------
    */
    'debit_path' => env('MKESH_DEBIT_PATH', '/DebitServlet/DebitSvlt'),
    'sp_transfer_path' => env('MKESH_SP_TRANSFER_PATH', '/sptransfer/sptransfer'),
    'transaction_status_path' => env('MKESH_STATUS_PATH', '/GetTransactionStatus/GetStatusSvlt'),
];
