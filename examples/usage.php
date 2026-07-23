<?php

/**
 * =============================================================================
 *  techsolutions/mkesh — GUIA DE UTILIZACAO
 * =============================================================================
 *
 * Ficheiro de referencia: mostra TODAS as operacoes, de ponta a ponta, tanto em
 * PHP puro como em Laravel. Cada bloco esta comentado com o que acontece no
 * lado do MKESH.
 *
 * Este ficheiro NAO faz chamadas reais quando executado — as chamadas de rede
 * estao dentro de funcoes que so correm se as invocar. Para um teste real
 * contra o agregador use antes:
 *
 *     php examples/mkesh-test.php debit 258823040400 25
 *
 * INDICE
 *   1.  Configuracao (PHP puro)
 *   2.  Configuracao (Laravel)
 *   3.  Gerar ids de transacao
 *   4.  C2B — cobrar um cliente (debit)
 *   5.  B2C — pagar a um cliente (sptransfer)
 *   6.  Consultar estado (gettransactionstatus)
 *   7.  Receber o callback + responder ao MKESH
 *   8.  Tratar erros com o enum ErrorCode
 *   9.  Enums disponiveis
 *   10. Laravel: rota, migrations, models, job
 */

declare(strict_types=1);

use TechSolutions\Mkesh\Callback\CallbackResponse;
use TechSolutions\Mkesh\Config\MkeshConfig;
use TechSolutions\Mkesh\Enum\CallbackResponseCode;
use TechSolutions\Mkesh\Enum\ErrorCode;
use TechSolutions\Mkesh\Enum\FriType;
use TechSolutions\Mkesh\Enum\TransactionStatus;
use TechSolutions\Mkesh\Error\ErrorCodes;
use TechSolutions\Mkesh\Exception\ConfigurationException;
use TechSolutions\Mkesh\Exception\ErrorResponseException;
use TechSolutions\Mkesh\Exception\MkeshException;
use TechSolutions\Mkesh\Exception\TransportException;
use TechSolutions\Mkesh\MkeshClient;
use TechSolutions\Mkesh\Request\DebitRequest;
use TechSolutions\Mkesh\Request\GetTransactionStatusRequest;
use TechSolutions\Mkesh\Request\SpTransferRequest;
use TechSolutions\Mkesh\ValueObject\Fri;
use TechSolutions\Mkesh\ValueObject\Money;

require __DIR__ . '/../vendor/autoload.php';

/* =============================================================================
 * 1. CONFIGURACAO (PHP puro)
 * ========================================================================== */

function buildConfig(): MkeshConfig
{
    return new MkeshConfig(
        // Credenciais HTTP Basic dadas pelo provedor.
        username: getenv('MKESH_USERNAME') ?: 'ACME',
        password: getenv('MKESH_PASSWORD') ?: 'secret',

        // Carteira CREDITADA quando cobramos um cliente (C2B).
        serviceProviderFri: 'FRI:pagamKesh/USER',

        baseUrl: 'https://41.220.193.151',
        defaultCurrency: 'MZN',

        // Prefixo OBRIGATORIO. O pacote aplica-o sozinho a todos os ids.
        transactionPrefix: 'ACME',

        // O endpoint do callback e registado no lado do provedor. Isto aqui e
        // so referencia — nao vai no payload (ver sendCallbackUrl).
        callbackUrl: 'https://a-sua-app.example/mkesh/callback',

        // O host esta publicado num IP nu, logo o certificado nao bate certo
        // com o hostname. Aponte o CA que o provedor fornecer.
        verifySsl: true,
        sslCaBundle: null,
        timeout: 30.0,

        // Carteira DEBITADA nos pagamentos B2C. Na folha do provedor e uma FRI
        // diferente da de cima. Se ficar null, usa a serviceProviderFri.
        spTransferSendingFri: 'FRI:47225552/MM',

        // Deixe false: o payload documentado nao tem <callbackurl>.
        sendCallbackUrl: false,
    );
}

// Alternativa: a partir de um array (o mesmo formato do config/mkesh.php).
function buildConfigFromArray(): MkeshConfig
{
    return MkeshConfig::fromArray([
        'username' => 'ACME',
        'password' => 'secret',
        'service_provider_fri' => 'FRI:pagamKesh/USER',
        'sp_transfer_sending_fri' => 'FRI:47225552/MM',
        'transaction_prefix' => 'ACME',
        'callback_url' => 'https://a-sua-app.example/mkesh/callback',
        'base_url' => 'https://41.220.193.151',
        'default_currency' => 'MZN',
        'verify_ssl' => true,
        'timeout' => 30.0,
    ]);
}

/* =============================================================================
 * 2. CONFIGURACAO (Laravel)
 * =============================================================================
 *
 *   php artisan vendor:publish --tag=mkesh-config
 *   php artisan vendor:publish --tag=mkesh-migrations   (opcional)
 *   php artisan migrate
 *
 * .env:
 *   MKESH_USERNAME=ACME
 *   MKESH_PASSWORD=...
 *   MKESH_SP_FRI=FRI:pagamKesh/USER
 *   MKESH_SP_TRANSFER_FRI=FRI:47225552/MM
 *   MKESH_TRANSACTION_PREFIX=ACME
 *   MKESH_CALLBACK_URL=https://a-sua-app.example/mkesh/callback
 *
 * Depois, em qualquer sitio:
 *
 *   use TechSolutions\Mkesh\Laravel\Facades\Mkesh;
 *   $resposta = Mkesh::debit($pedido);
 *
 * Ou injecte no construtor (resolvido automaticamente pelo container):
 *
 *   public function __construct(private readonly MkeshClient $mkesh) {}
 */

/* =============================================================================
 * 3. IDS DE TRANSACAO
 * =============================================================================
 *
 * Regras do agregador:
 *   - todo o id tem de comecar pelo token de parceiro que o provedor lhe deu
 *     no onboarding. O 'ACME' usado aqui e so um PLACEHOLDER — o seu valor
 *     real vem deles e um prefixo errado faz o pedido ser recusado;
 *   - tem de ser unico por service provider — repetir da
 *     REFERENCE_ID_ALREADY_IN_USE. Nesse caso o original quase de certeza
 *     passou: consulte o estado, NAO gere um id novo;
 *   - GRAVE o id ANTES de enviar o pedido, senao uma falha de rede deixa-o sem
 *     saber que id foi usado.
 *
 * Sem prefixo configurado o SDK nao inventa nenhum: os ids passam intactos.
 */

function demonstrarIds(MkeshConfig $config): void
{
    // Id novo e unico, ja com prefixo: "ACME9F2C4A1B77E30D55"
    echo $config->newTransactionId() . PHP_EOL;

    // Com sufixo proprio (o seu nr. de encomenda, por exemplo): "ACMEORD-1234"
    echo $config->newTransactionId('ORD-1234') . PHP_EOL;

    // applyPrefix() e idempotente — chamar duas vezes nao duplica o prefixo.
    echo $config->applyPrefix('000001') . PHP_EOL;       // ACME000001
    echo $config->applyPrefix('ACME000001') . PHP_EOL;   // ACME000001
    echo $config->applyPrefix('ORD-1234') . PHP_EOL;     // ACMEORD-1234
}

/* =============================================================================
 * 4. C2B — COBRAR UM CLIENTE (debitrequest v1_1)
 * =============================================================================
 *
 * FLUXO: o dinheiro NAO se move na resposta. O cliente recebe um SMS
 * (APPROVAL_REQUESTING_PENDING) e tem de aprovar no telemovel antes do pedido
 * expirar. So depois chega o callback com o resultado final.
 */

function cobrar(MkeshClient $mkesh): void
{
    $config = $mkesh->config();
    $id = $config->newTransactionId();   // GRAVE ISTO NA BD ANTES DE ENVIAR

    $pedido = new DebitRequest(
        fromFri: Fri::msisdn('258823040400'),   // quem paga
        amount: Money::of(25, 'MZN'),
        externalTransactionId: $id,
        // referenceId: por omissao = externalTransactionId. E por ele que o
        // gettransactionstatus procura mais tarde.
        // toFri: por omissao = serviceProviderFri da config.
    );

    // Atalho equivalente para o caso simples:
    $pedido = DebitRequest::charge('258823040400', Money::of(25), $id);

    // Ver o XML antes de enviar (util para depurar com o provedor):
    echo $pedido->toXml($config) . PHP_EOL;

    $resposta = $mkesh->debit($pedido);

    $resposta->transactionId;          // "3171312" — id do lado da plataforma
    $resposta->status;                 // TransactionStatus::PENDING
    $resposta->approvalId;             // "470390"
    $resposta->isPending();            // true

    // NAO marque a encomenda como paga aqui. PENDING quer dizer
    // "a espera da aprovacao do cliente".
}

/* =============================================================================
 * 5. B2C — PAGAR A UM CLIENTE (sptransferrequest v1_2)
 * =============================================================================
 *
 * Ao contrario do debito, isto liquida na hora: resposta com sucesso = dinheiro
 * transferido.
 */

function pagar(MkeshClient $mkesh): void
{
    $config = $mkesh->config();
    $id = $config->newTransactionId();

    $pedido = new SpTransferRequest(
        receivingFri: Fri::msisdn('258823040400'),   // quem recebe
        amount: Money::of(25, 'MZN'),
        providerTransactionId: $id,
        // sendingFri: por omissao = spTransferSendingFri da config.
        // referenceId: por omissao = providerTransactionId.
    );

    // Atalho equivalente:
    $pedido = SpTransferRequest::payout('258823040400', Money::of(25), $id);

    $resposta = $mkesh->transfer($pedido);

    $resposta->transactionId;            // "3282002"
    $resposta->providerTransactionId;    // "ACME-XXXXXX"
}

/* =============================================================================
 * 6. CONSULTAR ESTADO (gettransactionstatusrequest v1_3)
 * =============================================================================
 *
 * O ramo "No Response From MKESH" do diagrama. Use quando o callback nao chegou.
 * A procura e feita pelo REFERENCEID.
 */

function consultarEstado(MkeshClient $mkesh): void
{
    // Forma curta — o prefixo e aplicado automaticamente.
    $estado = $mkesh->getTransactionStatus('000001');

    // Forma explicita, se preferir construir o pedido:
    $estado = $mkesh->getTransactionStatus(new GetTransactionStatusRequest('000001'));

    $estado->financialTransactionId;     // "3171312"
    $estado->status;                     // TransactionStatus::SUCCESSFUL
    $estado->providerTransactionId;      // "ACME000001" (ausente quando FAILED)

    $estado->isSuccessful();
    $estado->isFailed();
    $estado->status->isSettled();        // true se SUCCESSFUL ou FAILED
}

/* =============================================================================
 * 7. RECEBER O CALLBACK E RESPONDER
 * =============================================================================
 *
 * O agregador faz POST do debitcompletedrequest para o endpoint que registou
 * com eles. ATENCAO: responder 200 vazio NAO chega — o MKESH continua a
 * reenviar. Tem de devolver o documento <ResponseCode>.
 */

function receberCallback(MkeshClient $mkesh, string $corpoDoPedido): string
{
    try {
        $callback = $mkesh->parseDebitCompleted($corpoDoPedido);
    } catch (MkeshException) {
        // Corpo invalido — devolva 400 para nao ficarem a reenviar para sempre.
        return '';
    }

    $callback->transactionId;             // id da plataforma
    $callback->externalTransactionId;     // o NOSSO id, ja com prefixo
    $callback->referenceId;
    $callback->status;                    // TransactionStatus::SUCCESSFUL
    $callback->communicationChannel;      // "http-sp"
    $callback->receiver->fri;             // "FRI:1360073/MM"
    $callback->receiver->msisdn;
    $callback->receiver->language;        // "en"

    if ($callback->isSuccessful()) {
        // marcar a encomenda $callback->externalTransactionId como paga
    }

    // O callback pode ser reenviado — faca isto idempotente: procure pelo
    // externalTransactionId e ignore se a linha ja estiver liquidada.

    /*
     * Corpo da resposta, com Content-Type: CallbackResponse::CONTENT_TYPE
     *
     *   <?xml version="1.0" encoding="utf-8"?><ResponseCode>SUCCESS</ResponseCode>
     *
     * (Nota: o "?" seguido de ">" so pode aparecer dentro de um comentario de
     * bloco como este — num comentario de linha fecharia o modo PHP.)
     */
    return CallbackResponse::success()->toXml();
}

// O equivalente B2C (initiatetransfercompleted) e igual:
function receberCallbackDeTransferencia(MkeshClient $mkesh, string $corpo): void
{
    $callback = $mkesh->parseInitiateTransferCompleted($corpo);

    $callback->financialTransactionId;
    $callback->externalTransactionId;
    $callback->status->isSuccessful();
    $callback->receiver->msisdn;
}

/* =============================================================================
 * 8. TRATAR ERROS
 * =============================================================================
 *
 * Erros de negocio vem como <errorResponse errorcode="..."/> e sao lancados
 * como ErrorResponseException. Toda a hierarquia implementa MkeshException.
 */

function tratarErros(MkeshClient $mkesh, DebitRequest $pedido): void
{
    try {
        $mkesh->debit($pedido);
    } catch (ErrorResponseException $e) {
        // -- Acesso a string crua e a descricao do catalogo (670 codigos) ----
        $e->getErrorCode();      // "ACCOUNTHOLDER_WITH_FRI_NOT_FOUND"
        $e->getDescription();    // "Account holder with given FRI could not be found"
        $e->getArguments();      // ["fri" => "FRI:258823040420/MSISDN"]
        $e->getArgument('fri');
        $e->getRawXml();         // corpo original, para auditoria

        // -- Ou o enum, com classificacao pronta a usar ----------------------
        $codigo = $e->code();    // ErrorCode

        if ($codigo->isDuplicate()) {
            // REFERENCE_ID_ALREADY_IN_USE: o pedido original quase de certeza
            // passou. NAO reenvie com id novo — consulte o estado.
            return;
        }

        if ($codigo->isRetryable()) {
            // Transitorio (ex.: consultamos cedo demais). Tente outra vez.
            return;
        }

        if ($codigo->isCustomerFault()) {
            // Saldo insuficiente, conta inactiva, PIN errado, aprovacao
            // expirada... mostre uma mensagem ao cliente; reenviar nao ajuda.
            return;
        }

        if ($codigo->isAuthFailure()) {
            // Credenciais erradas ou IP de origem nao autorizado.
            return;
        }

        // Comparacao directa aceita enum ou string:
        $e->is(ErrorCode::TRANSACTION_NOT_FOUND);
        $e->is('TRANSACTION_NOT_FOUND');
        $e->is(ErrorCode::AMOUNT_INVALID, ErrorCode::INVALID_CURRENCY);

        // match() exaustivo:
        $mensagem = match ($codigo) {
            ErrorCode::AUTHORIZATION_CURRENT_BALANCE_TOO_LOW => 'Saldo insuficiente.',
            ErrorCode::TRANSACTION_REQUEST_EXPIRED => 'Nao aprovou a tempo.',
            ErrorCode::INCORRECT_PIN => 'PIN incorrecto.',
            ErrorCode::ACCOUNTHOLDER_NOT_ACTIVE => 'Conta mKesh inactiva.',
            default => 'Nao foi possivel processar o pagamento.',
        };
    } catch (TransportException $e) {
        // Rede, TLS, timeout, corpo vazio ou XML invalido.
        // ATENCAO: pode ter passado do lado deles. Consulte o estado antes de
        // reenviar.
    } catch (ConfigurationException $e) {
        // Configuracao em falta ou invalida.
    } catch (MkeshException $e) {
        // Qualquer outra falha do pacote.
    }

    // O catalogo completo tambem esta acessivel directamente:
    ErrorCodes::description('TRANSACTION_NOT_FOUND');
    ErrorCodes::has('REFERENCE_ID_ALREADY_IN_USE');
    ErrorCodes::all();
}

/* =============================================================================
 * 9. ENUMS DISPONIVEIS
 * ========================================================================== */

function demonstrarEnums(): void
{
    // -- Estado de uma transacao ---------------------------------------------
    TransactionStatus::PENDING;
    TransactionStatus::SUCCESSFUL;
    TransactionStatus::FAILED;
    TransactionStatus::UNKNOWN;

    // Tolerante a maiusculas/minusculas e a sinonimos: o diagrama do provedor
    // escreve SUCCESS/FAILURE, os payloads escrevem SUCCESSFUL/FAILED.
    TransactionStatus::fromWire('SUCCESS');    // SUCCESSFUL
    TransactionStatus::fromWire('failure');    // FAILED
    TransactionStatus::fromWire('algo novo');  // UNKNOWN (nunca rebenta)

    TransactionStatus::SUCCESSFUL->isSettled();  // true — estado terminal
    TransactionStatus::PENDING->isSettled();     // false — continue a consultar

    // -- Codigo devolvido ao MKESH no callback --------------------------------
    CallbackResponseCode::SUCCESS;
    CallbackResponseCode::FAILURE;
    CallbackResponse::of(CallbackResponseCode::SUCCESS)->toXml();

    // -- Codigos de erro da plataforma ---------------------------------------
    ErrorCode::fromWire('TRANSACTION_NOT_FOUND');
    ErrorCode::TRANSACTION_NOT_FOUND->description();
    ErrorCode::REFERENCE_ID_ALREADY_IN_USE->isDuplicate();
    ErrorCode::TRANSACTION_REQUEST_EXPIRED->isExpired();

    // -- Tipos de FRI ---------------------------------------------------------
    FriType::MSISDN;         // FRI:258823040400/MSISDN — numero de telemovel
    FriType::USER;           // FRI:pagamKesh/USER      — conta de SP
    FriType::MOBILE_MONEY;   // FRI:1360073/MM          — conta mobile money

    Fri::msisdn('258823040400');
    Fri::user('pagamKesh');
    Fri::mobileMoney('1360073');
    Fri::fromString('FRI:258823040400/MSISDN');

    // -- Dinheiro -------------------------------------------------------------
    // Guardado como string normalizada, para nao apanhar erros de virgula
    // flutuante ao serializar o XML.
    Money::of(25);                 // 25 MZN
    Money::of('25.50', 'MZN');
    Money::of(25.5)->amount;       // "25.5"
}

/* =============================================================================
 * 10. LARAVEL — FICHEIROS PRONTOS A COPIAR
 * =============================================================================
 *
 * MIGRATIONS (ja incluidas no pacote, correm com php artisan migrate):
 *   database/migrations/..._create_mkesh_transactions_table.php
 *       Livro-razao local: uma linha por debito/transferencia.
 *   database/migrations/..._create_mkesh_responses_table.php
 *       Auditoria: todo o callback recebido e toda a resposta obtida, com o
 *       XML cru. E o que serve de prova numa reconciliacao com o provedor.
 *
 * MODELS / CONTROLLERS / JOBS (copie de examples/Laravel/):
 *   MkeshTransaction.php            model do livro-razao
 *   MkeshResponse.php               model da auditoria
 *   MkeshPaymentService.php         cobrar/pagar com persistencia
 *   MkeshCallbackController.php     webhook que reconcilia e responde SUCCESS
 *   ReconcileMkeshTransaction.php   job que faz polling quando o callback falha
 *
 * ROTA (fora do grupo com CSRF):
 *
 *   // routes/api.php
 *   Route::post('/mkesh/callback', MkeshCallbackController::class);
 *
 * UTILIZACAO TIPICA:
 *
 *   $transaccao = app(MkeshPaymentService::class)->charge(
 *       msisdn:  '258823040400',
 *       amount:  '25.00',
 *       payable: $encomenda,       // liga o pagamento a sua encomenda
 *   );
 *
 *   $transaccao->status;                 // TransactionStatus::PENDING
 *   $transaccao->external_transaction_id;
 *   $transaccao->responses;              // auditoria de tudo o que chegou
 *
 * O servico ja despacha o job de reconciliacao. Nao ha mais nada a fazer: ou
 * chega o callback, ou o job apanha o estado por polling.
 */

echo "Este ficheiro e um guia de leitura. Veja examples/mkesh-test.php para um teste real.\n";
