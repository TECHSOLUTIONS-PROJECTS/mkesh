<?php

/**
 * MKESH — script de teste manual (CLI).
 *
 * USO:
 *   php examples/mkesh-test.php debit    <msisdn> [valor]     # C2B: cobrar o cliente
 *   php examples/mkesh-test.php transfer <msisdn> [valor]     # B2C: pagar ao cliente
 *   php examples/mkesh-test.php status   <referenceid>        # consultar estado
 *
 * EXEMPLOS:
 *   php examples/mkesh-test.php debit 258823040400 25
 *   php examples/mkesh-test.php transfer 258823040400 10
 *   php examples/mkesh-test.php status 000001
 *
 * Preencha as credenciais no bloco CONFIG abaixo, OU exporte as variáveis de
 * ambiente (MKESH_USERNAME, MKESH_PASSWORD, MKESH_SP_FRI, MKESH_TRANSACTION_PREFIX...).
 */

declare(strict_types=1);

use BrilliantMind\Mkesh\Config\MkeshConfig;
use BrilliantMind\Mkesh\Exception\ErrorResponseException;
use BrilliantMind\Mkesh\Exception\MkeshException;
use BrilliantMind\Mkesh\MkeshClient;
use BrilliantMind\Mkesh\Request\DebitRequest;
use BrilliantMind\Mkesh\Request\GetTransactionStatusRequest;
use BrilliantMind\Mkesh\Request\SpTransferRequest;
use BrilliantMind\Mkesh\ValueObject\Fri;
use BrilliantMind\Mkesh\ValueObject\Money;

require __DIR__ . '/../vendor/autoload.php';

/* ===========================================================================
 * CONFIG — PREENCHA AQUI (ou deixe vazio para usar variáveis de ambiente)
 * ======================================================================== */
$CONFIG = [
    'username'             => getenv('MKESH_USERNAME') ?: '',
    'password'             => getenv('MKESH_PASSWORD') ?: '',
    'service_provider_fri' => getenv('MKESH_SP_FRI') ?: 'FRI:pagamKesh/USER',

    // Carteira de origem nos pagamentos B2C (sptransfer), ex.: 'FRI:47225552/MM'.
    // Vazio = usa a service_provider_fri acima.
    'sp_transfer_sending_fri' => getenv('MKESH_SP_TRANSFER_FRI') ?: null,

    // Prefixo obrigatório nos ids (ex.: 'MTL'). O SDK aplica-o automaticamente.
    'transaction_prefix'   => getenv('MKESH_TRANSACTION_PREFIX') ?: '',
    'base_url'             => getenv('MKESH_BASE_URL') ?: 'https://41.220.193.151',
    'default_currency'     => getenv('MKESH_CURRENCY') ?: 'MZN',
    'callback_url'         => getenv('MKESH_CALLBACK_URL') ?: null,

    // O host sandbox usa certificado self-signed num IP. Para o teste de conexão
    // deixamos a verificação TLS DESLIGADA. Ligue (true) em produção com CA própria.
    'verify_ssl'           => filter_var(getenv('MKESH_VERIFY_SSL') ?: 'false', FILTER_VALIDATE_BOOL),
    'timeout'              => 30.0,
];
/* ===========================================================================
 * Fim da CONFIG
 * ======================================================================== */

/**
 * Normaliza um número de Moçambique para MSISDN com código do país (258...).
 *   833631331    -> 258833631331
 *   0833631331   -> 258833631331
 *   +258833631331-> 258833631331
 *   258833631331 -> 258833631331
 */
function normalizeMsisdn(string $raw): string
{
    $digits = preg_replace('/\D+/', '', $raw) ?? '';
    $digits = ltrim($digits, '0');           // remove zeros à esquerda (ex.: 08...)

    if (str_starts_with($digits, '258')) {
        return $digits;
    }
    if (strlen($digits) === 9) {             // número nacional sem código de país
        return '258' . $digits;
    }

    return $digits;                          // já em formato internacional ou outro
}

function line(string $char = '-'): void
{
    echo str_repeat($char, 70) . PHP_EOL;
}

function out(string $label, string $value): void
{
    echo str_pad($label, 26) . ': ' . $value . PHP_EOL;
}

$mode = $argv[1] ?? null;
$arg2 = $argv[2] ?? null;
$arg3 = $argv[3] ?? null;

if ($mode === null || $arg2 === null) {
    fwrite(STDERR, "Uso:\n");
    fwrite(STDERR, "  php examples/mkesh-test.php debit    <msisdn> [valor]\n");
    fwrite(STDERR, "  php examples/mkesh-test.php transfer <msisdn> [valor]\n");
    fwrite(STDERR, "  php examples/mkesh-test.php status   <referenceid>\n");
    exit(2);
}

if ($CONFIG['username'] === '' || $CONFIG['password'] === '') {
    fwrite(STDERR, "ERRO: preencha 'username' e 'password' no bloco CONFIG (ou exporte MKESH_USERNAME/MKESH_PASSWORD).\n");
    exit(2);
}

try {
    $config = MkeshConfig::fromArray($CONFIG);
    $client = MkeshClient::create($config);

    // Id único por execução (deve ser único por service provider — um id
    // repetido é rejeitado com REFERENCE_ID_ALREADY_IN_USE).
    $txId = $config->newTransactionId(date('YmdHis'));

    line('=');
    out('Modo', strtoupper((string) $mode));
    out('Host', $config->baseUrl);
    out('SP FRI (crédito C2B)', (string) $config->serviceProviderFri);
    out('SP FRI (origem B2C)', (string) $config->spTransferSendingFri);
    out('Prefixo de transação', $config->transactionPrefix ?? '(nenhum)');
    out('Verificação TLS', $config->verifySsl ? 'LIGADA' : 'DESLIGADA');
    line('=');

    if ($config->transactionPrefix === null || $config->transactionPrefix === '') {
        fwrite(STDERR, "AVISO: MKESH_TRANSACTION_PREFIX está vazio. O agregador exige que\n");
        fwrite(STDERR, "       os ids comecem pelo prefixo do parceiro (ex.: MTL).\n\n");
    }

    switch ($mode) {
        case 'debit':
            $msisdn = normalizeMsisdn($arg2);
            out('MSISDN', $arg2 . ' -> ' . $msisdn);
            line();
            $amount = new Money($arg3 ?? '1', $config->defaultCurrency);
            $request = new DebitRequest(
                fromFri: Fri::msisdn($msisdn),
                amount: $amount,
                externalTransactionId: $txId,
                referenceId: $txId,
            );

            echo "Request XML enviado:\n";
            echo $request->toXml($config) . PHP_EOL;
            line();

            $response = $client->debit($request);

            echo "Resposta (debitresponse):\n";
            out('transactionId', $response->transactionId);
            out('status', $response->status->value);
            out('approvalId', $response->approvalId ?? '(nenhum)');
            echo PHP_EOL;
            echo "→ Geralmente PENDING: o cliente aprova no telemóvel e o resultado\n";
            echo "  final chega depois via callback (debitcompleted).\n";
            echo "  Para consultar manualmente: php examples/mkesh-test.php status {$txId}\n";
            break;

        case 'transfer':
            $msisdn = normalizeMsisdn($arg2);
            out('MSISDN', $arg2 . ' -> ' . $msisdn);
            line();
            $amount = new Money($arg3 ?? '1', $config->defaultCurrency);
            $request = new SpTransferRequest(
                receivingFri: Fri::msisdn($msisdn),
                amount: $amount,
                providerTransactionId: $txId,
                referenceId: $txId,
            );

            echo "Request XML enviado:\n";
            echo $request->toXml($config) . PHP_EOL;
            line();

            $response = $client->transfer($request);

            echo "Resposta (sptransferresponse):\n";
            out('transactionId', $response->transactionId);
            out('providerTransactionId', $response->providerTransactionId ?? '(nenhum)');
            break;

        case 'status':
            $request = new GetTransactionStatusRequest($arg2);

            echo "Request XML enviado:\n";
            echo $request->toXml($config) . PHP_EOL;
            line();

            $response = $client->getTransactionStatus($request);

            echo "Resposta (gettransactionstatusresponse):\n";
            out('financialTransactionId', $response->financialTransactionId);
            out('status', $response->status->value);
            out('providerTransactionId', $response->providerTransactionId ?? '(nenhum)');
            break;

        default:
            fwrite(STDERR, "Modo desconhecido: {$mode} (use debit | transfer | status)\n");
            exit(2);
    }

    line('=');
    echo "OK\n";
    exit(0);
} catch (ErrorResponseException $e) {
    line('!');
    fwrite(STDERR, "ERRO DA PLATAFORMA\n");
    fwrite(STDERR, '  código     : ' . $e->getErrorCode() . "\n");
    fwrite(STDERR, '  descrição  : ' . ($e->getDescription() ?? '(desconhecida)') . "\n");
    foreach ($e->getArguments() as $name => $value) {
        fwrite(STDERR, "  arg[{$name}] : {$value}\n");
    }
    if ($e->getRawXml() !== null) {
        fwrite(STDERR, "  XML        : " . $e->getRawXml() . "\n");
    }
    exit(1);
} catch (MkeshException $e) {
    line('!');
    fwrite(STDERR, 'ERRO: ' . $e->getMessage() . "\n");
    exit(1);
}
