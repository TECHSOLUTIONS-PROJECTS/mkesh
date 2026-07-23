# techsolutions/mkesh

> Um projecto da **[TechSolutions](https://github.com/TECHSOLUTIONS-PROJECTS)**,
> desenvolvido por **[Osvaldo Geraldo Manjate](https://github.com/osvaldogeraldo)**
> enquanto colaborador da TechSolutions.

Pacote PHP para a integração **MKESH / PagamKesh** através do **Agregador
Ericsson EWP** (API "XML over HTTP"), com suporte nativo para Laravel.

O pacote constrói e interpreta todo o XML por si e expõe objectos tipados de
pedido/resposta. Autenticação HTTP Basic, transporte PSR-18 (Guzzle por
omissão).

| Operação | Método | Fluxo | Endpoint (por omissão) |
|----------|--------|-------|------------------------|
| Debit request | `debit()` | C2B – cobrar um cliente | `/DebitServlet/DebitSvlt` |
| SP transfer | `transfer()` | B2C – pagar a um cliente | `/sptransfer/sptransfer` |
| Get transaction status | `getTransactionStatus()` | recuperar um resultado | `/GetTransactionStatus/GetStatusSvlt` |
| Debit completed | `parseDebitCompleted()` | callback assíncrono C2B | *(o seu webhook)* |
| Transfer completed | `parseInitiateTransferCompleted()` | callback assíncrono B2C | *(o seu webhook)* |

## Índice

1. [Requisitos](#1-requisitos)
2. [Instalação](#2-instalação)
3. [Configuração](#3-configuração) — incluindo [o prefixo de transacção](#35-o-prefixo-de-transacção-em-detalhe)
4. [Como funciona o fluxo C2B](#4-como-funciona-o-fluxo-c2b)
5. [Guia rápido Laravel](#5-guia-rápido-laravel) — do zero ao primeiro pagamento
6. [Usar numa classe Laravel](#6-usar-numa-classe-laravel) — controller, service, job, command
7. [Operações em detalhe](#7-operações-em-detalhe) — payloads completos
8. [Enums](#8-enums)
9. [Erros](#9-erros)
10. [Base de dados](#10-base-de-dados)
11. [TLS, IP de origem e cliente HTTP](#11-tls-ip-de-origem-e-cliente-http)
12. [Testes e resolução de problemas](#12-testes-e-resolução-de-problemas)

> [`examples/usage.php`](examples/usage.php) é um guia anotado com tudo isto num
> só ficheiro de código.

---

## 1. Requisitos

- PHP 8.1+
- Extensões `ext-dom` e `ext-libxml`
- Um cliente HTTP PSR-18 (o Guzzle vem incluído)
- Laravel 10, 11 ou 12 (opcional — o pacote funciona em PHP puro)

---

## 2. Instalação

```bash
composer require techsolutions/mkesh
```

Em Laravel o `MkeshServiceProvider` e a facade `Mkesh` são registados
automaticamente. Publique a configuração:

```bash
php artisan vendor:publish --tag=mkesh-config
php artisan migrate
```

As migrations vêm dentro do pacote e correm directamente com `migrate`. Só
precisa de as publicar se quiser alterar o schema:

```bash
php artisan vendor:publish --tag=mkesh-migrations
```

---

## 3. Configuração

### 3.1 Variáveis de ambiente

```dotenv
# Credenciais HTTP Basic (dadas pelo provedor)
MKESH_USERNAME=ACME
MKESH_PASSWORD=

# FRI creditada quando cobra um cliente (C2B)
MKESH_SP_FRI=FRI:pagamKesh/USER

# Carteira debitada num pagamento B2C. Vazio = usa a MKESH_SP_FRI.
MKESH_SP_TRANSFER_FRI=FRI:47225552/MM

# Prefixo obrigatório nos ids. O pacote aplica-o sozinho.
MKESH_TRANSACTION_PREFIX=ACME

# O seu endpoint de callback — registe este URL junto do provedor
MKESH_CALLBACK_URL=https://a-sua-app.co.mz/api/mkesh/callback

MKESH_BASE_URL=https://41.220.193.151
MKESH_CURRENCY=MZN
MKESH_TIMEOUT=30

MKESH_VERIFY_SSL=true
MKESH_SSL_CA_BUNDLE=
```

Referência completa (ficheiro pronto a copiar em [`.env.example`](.env.example)):

| Variável | Omissão | Descrição |
|----------|---------|-----------|
| `MKESH_USERNAME` | — | Utilizador HTTP Basic |
| `MKESH_PASSWORD` | — | Senha HTTP Basic |
| `MKESH_SP_FRI` | `FRI:pagamKesh/USER` | FRI creditada num débito (C2B) |
| `MKESH_SP_TRANSFER_FRI` | *(usa `MKESH_SP_FRI`)* | Carteira debitada num pagamento (B2C) |
| `MKESH_BASE_URL` | `https://41.220.193.151` | Host do agregador |
| `MKESH_CURRENCY` | `MZN` | Moeda por omissão |
| `MKESH_TRANSACTION_PREFIX` | — | Prefixo forçado nos ids (ex.: `ACME`) |
| `MKESH_CALLBACK_URL` | — | O seu endpoint de callback |
| `MKESH_SEND_CALLBACK_URL` | `false` | Emitir `<callbackurl>` dentro do débito |
| `MKESH_VERIFY_SSL` | `true` | Verificar o certificado TLS |
| `MKESH_SSL_CA_BUNDLE` | — | Caminho para o CA de verificação |
| `MKESH_TIMEOUT` | `30` | Timeout por pedido (segundos) |
| `MKESH_DEBIT_PATH` | `/DebitServlet/DebitSvlt` | Path do débito |
| `MKESH_SP_TRANSFER_PATH` | `/sptransfer/sptransfer` | Path da transferência |
| `MKESH_STATUS_PATH` | `/GetTransactionStatus/GetStatusSvlt` | Path da consulta |

### 3.2 Três regras rígidas do agregador

- **O prefixo é obrigatório.** Todo o `externaltransactionid` / `referenceid`
  tem de começar pelo token de parceiro que o provedor lhe atribuir. Defina-o
  uma vez na configuração e passe ids simples — o pacote prefixa-os, de forma
  idempotente. Ver [secção 3.5](#35-o-prefixo-de-transacção-em-detalhe).
- **Os ids têm de ser únicos por service provider.** Reutilizar um dá
  `REFERENCE_ID_ALREADY_IN_USE`. Use `$config->newTransactionId()` e **grave o
  valor antes** de enviar o pedido.
- **O endpoint do callback é registado do lado do provedor,** não vai em cada
  pedido. Dê-lhes o URL; o pacote não coloca `<callbackurl>` no payload do
  débito a não ser que active `sendCallbackUrl: true`.

### 3.3 PHP puro (sem Laravel)

```php
use TechSolutions\Mkesh\Config\MkeshConfig;
use TechSolutions\Mkesh\MkeshClient;

$config = new MkeshConfig(
    username: 'ACME',
    password: 'a-sua-senha',
    serviceProviderFri: 'FRI:pagamKesh/USER',   // creditada no débito (C2B)
    transactionPrefix: 'ACME',
    callbackUrl: 'https://a-sua-app.co.mz/api/mkesh/callback',
    spTransferSendingFri: 'FRI:47225552/MM',    // debitada no pagamento (B2C)
);

$mkesh = MkeshClient::create($config);
```

Ou a partir de um array, com o mesmo formato do `config/mkesh.php`:

```php
$config = MkeshConfig::fromArray([
    'username' => 'ACME',
    'password' => 'a-sua-senha',
    'service_provider_fri' => 'FRI:pagamKesh/USER',
    'sp_transfer_sending_fri' => 'FRI:47225552/MM',
    'transaction_prefix' => 'ACME',
]);
```

### 3.4 Checklist de onboarding

A folha do provedor deixa o bloco por ambiente em branco. Estes valores têm de
ser acordados com eles **separadamente para teste e produção**:

| Valor | Direcção | Corresponde a |
|-------|----------|---------------|
| Endereço IP de origem | você → provedor | o IP de saída que eles autorizam |
| URL de callback | você → provedor | `MKESH_CALLBACK_URL` |
| Utilizador / senha | provedor → você | `MKESH_USERNAME` / `MKESH_PASSWORD` |
| Nr. de conta / MSISDN | provedor → você | `MKESH_SP_FRI` / `MKESH_SP_TRANSFER_FRI` |
| Prefixo de transacção | provedor → você | `MKESH_TRANSACTION_PREFIX` |
| URL base | provedor → você | `MKESH_BASE_URL` |

### 3.5 O prefixo de transacção em detalhe

> Nos exemplos deste README o prefixo é `ACME` — é só um **placeholder**. O seu
> valor real é atribuído pelo provedor no onboarding e costuma ser uma sigla
> curta da sua empresa. Nunca o invente: um id com prefixo errado é recusado.

O agregador identifica o service provider pelo prefixo dos ids. É por isso que
todo o `externaltransactionid`, `providertransactionid` e `referenceid` tem de
começar por ele.

Configure-o **uma vez**:

```dotenv
MKESH_TRANSACTION_PREFIX=ACME
```

```php
$config = new MkeshConfig(
    // ...
    transactionPrefix: 'ACME',
);
```

E a partir daí passe ids simples. O pacote aplica o prefixo sozinho, em todos os
pedidos:

```php
$config->applyPrefix('000001');       // "ACME000001"
$config->applyPrefix('ACME000001');   // "ACME000001"  — idempotente, não duplica
$config->applyPrefix('ORD-1234');     // "ACMEORD-1234"
```

**Gerar um id novo.** `newTransactionId()` devolve o prefixo mais um sufixo
aleatório de 16 caracteres hexadecimais (8 bytes de `random_bytes`):

```php
$config->newTransactionId();             // "ACME9F2C4A1B77E30D55"
$config->newTransactionId('ORD-1234');   // "ACMEORD-1234"  — o seu nr. de encomenda
$config->newTransactionId('000001');     // "ACME000001"
```

| Chamada | Resultado | Quando usar |
|---------|-----------|-------------|
| `newTransactionId()` | `ACME9F2C4A1B77E30D55` | Caso geral — não há nada seu a que amarrar o id |
| `newTransactionId('ORD-1234')` | `ACMEORD-1234` | Quer o id rastreável até à encomenda/factura |
| `applyPrefix($id)` | `ACME<id>` | Já tem um id gravado e só quer garantir o prefixo |

**Três coisas a saber:**

1. **Grave o id antes de enviar o pedido.** O agregador recusa um id repetido
   com `REFERENCE_ID_ALREADY_IN_USE`, e nesse caso o original quase de certeza
   passou — a resposta correcta é consultar o estado, não gerar um id novo.
2. **É idempotente.** Passar um id que já tem o prefixo não o duplica, portanto
   pode chamar `applyPrefix()` à vontade sem verificar antes.
3. **Sem prefixo configurado o pacote não inventa nenhum** — `applyPrefix()`
   devolve o id intacto. Isto é deliberado: se o provedor não lhe exigir prefixo,
   deixe `MKESH_TRANSACTION_PREFIX` vazio e nada muda.

Se usar prefixos diferentes em teste e em produção (é o habitual), é só a
variável de ambiente que muda — nenhum código seu é afectado.

---

## 4. Como funciona o fluxo C2B

Cobrar um cliente é assíncrono. **A resposta do débito só diz que o pedido foi
aceite — o dinheiro ainda não se moveu.**

```text
  Parceiro                         MKESH                        Cliente
     │                               │                              │
     │  1. debitrequest v1_1         │                              │
     ├──────────────────────────────►│                              │
     │  2. debitresponse PENDING     │                              │
     │◄──────────────────────────────┤   SMS: aprovação pendente    │
     │                               ├─────────────────────────────►│
     │                               │   aprova antes de expirar    │
     │                               │◄─────────────────────────────┤
     │  3. debitcompletedrequest v1_2│                              │
     │◄──────────────────────────────┤                              │
     │     <ResponseCode>SUCCESS</…> │   SMS: débito concluído      │
     ├──────────────────────────────►├─────────────────────────────►│
     │                               │                              │
     │  ─ se o passo 3 nunca chegar ─│                              │
     │  4. gettransactionstatus v1_3 │                              │
     ├──────────────────────────────►│                              │
     │     SUCCESSFUL / FAILED       │                              │
     │◄──────────────────────────────┤                              │
```

Na prática:

1. `debit()` devolve `PENDING` e um `approvalid`. Grave os ids e pare.
2. O cliente aprova no telemóvel. Não há sinal síncrono deste passo.
3. O agregador faz POST do `debitcompletedrequest` para o seu endpoint. Tem de
   responder `<ResponseCode>SUCCESS</ResponseCode>` — um `200` vazio **não** é
   aceite e o callback será reenviado.
4. Se o callback nunca chegar, consulte `getTransactionStatus($referenceId)`
   até o estado ficar liquidado.

Os pagamentos B2C (`transfer()`) são mais simples: uma `sptransferresponse` com
sucesso significa que o dinheiro foi transferido.

---

## 5. Guia rápido Laravel

Do zero ao primeiro pagamento em cinco passos.

**Passo 1 — instalar e configurar**

```bash
composer require techsolutions/mkesh
php artisan vendor:publish --tag=mkesh-config
php artisan migrate
```

Preencha o `.env` conforme a [secção 3.1](#31-variáveis-de-ambiente).

**Passo 2 — copiar os ficheiros de exemplo**

De [`examples/Laravel/`](examples/Laravel/) para a sua aplicação:

| Ficheiro de exemplo | Destino |
|---------------------|---------|
| `MkeshTransaction.php` | `app/Models/` |
| `MkeshResponse.php` | `app/Models/` |
| `MkeshPaymentService.php` | `app/Services/` |
| `MkeshCallbackController.php` | `app/Http/Controllers/` |
| `ReconcileMkeshTransaction.php` | `app/Jobs/` |

**Passo 3 — registar a rota do callback**

Fora do grupo protegido por CSRF, para o agregador conseguir chegar lá sem token:

```php
// routes/api.php
use App\Http\Controllers\MkeshCallbackController;

Route::post('/mkesh/callback', MkeshCallbackController::class);
```

**Passo 4 — dar o URL ao provedor**

`https://a-sua-app.co.mz/api/mkesh/callback`. Eles configuram-no do lado deles.
Confirme também que o IP de saída do seu servidor está autorizado.

**Passo 5 — cobrar**

```php
$transaccao = app(MkeshPaymentService::class)->charge(
    msisdn:  '258823040400',
    amount:  '25.00',
    payable: $encomenda,
);

$transaccao->status;   // TransactionStatus::PENDING
```

E já está. O serviço despacha sozinho o job de reconciliação: ou chega o
callback, ou o job apanha o resultado por polling.

---

## 6. Usar numa classe Laravel

### 6.1 Injecção no construtor (recomendado)

`MkeshClient` está registado no container como singleton — basta declarar o tipo:

```php
namespace App\Services;

use TechSolutions\Mkesh\MkeshClient;
use TechSolutions\Mkesh\Request\DebitRequest;
use TechSolutions\Mkesh\ValueObject\Money;

final class CheckoutService
{
    public function __construct(
        private readonly MkeshClient $mkesh,
    ) {
    }

    public function pagar(string $msisdn, string $valor): void
    {
        $id = $this->mkesh->config()->newTransactionId();

        // grave $id na sua base de dados AQUI, antes de enviar

        $resposta = $this->mkesh->debit(
            DebitRequest::charge($msisdn, Money::of($valor), $id),
        );
    }
}
```

### 6.2 Facade

```php
use TechSolutions\Mkesh\Laravel\Facades\Mkesh;

$id       = Mkesh::config()->newTransactionId();
$debito   = Mkesh::debit(DebitRequest::charge('258823040400', Money::of(25), $id));
$estado   = Mkesh::getTransactionStatus($id);
$callback = Mkesh::parseDebitCompleted($request->getContent());
```

Métodos disponíveis na facade:

| Método | Devolve |
|--------|---------|
| `Mkesh::debit($request)` | `DebitResponse` |
| `Mkesh::transfer($request)` | `SpTransferResponse` |
| `Mkesh::getTransactionStatus($ref)` | `TransactionStatusResponse` |
| `Mkesh::parseDebitCompleted($xml)` | `DebitCompletedNotification` |
| `Mkesh::parseInitiateTransferCompleted($xml)` | `InitiateTransferCompletedNotification` |
| `Mkesh::acknowledgeCallback()` | `CallbackResponse` |
| `Mkesh::config()` | `MkeshConfig` |

### 6.3 Controller que inicia um pagamento

```php
namespace App\Http\Controllers;

use App\Services\MkeshPaymentService;
use TechSolutions\Mkesh\Enum\ErrorCode;
use TechSolutions\Mkesh\Exception\ErrorResponseException;
use TechSolutions\Mkesh\Exception\TransportException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class PagamentoController extends Controller
{
    public function __construct(
        private readonly MkeshPaymentService $pagamentos,
    ) {
    }

    public function store(Request $request): JsonResponse
    {
        $dados = $request->validate([
            'msisdn' => ['required', 'regex:/^258[0-9]{9}$/'],
            'valor'  => ['required', 'numeric', 'min:1'],
        ]);

        try {
            $transaccao = $this->pagamentos->charge(
                msisdn: $dados['msisdn'],
                amount: (string) $dados['valor'],
            );
        } catch (ErrorResponseException $e) {
            $codigo = $e->code();

            // Erros que o cliente consegue resolver: mostre a mensagem.
            if ($codigo->isCustomerFault()) {
                return response()->json([
                    'mensagem' => match ($codigo) {
                        ErrorCode::AUTHORIZATION_CURRENT_BALANCE_TOO_LOW => 'Saldo insuficiente.',
                        ErrorCode::ACCOUNTHOLDER_NOT_ACTIVE => 'Conta mKesh inactiva.',
                        default => 'Não foi possível processar o pagamento.',
                    },
                ], 422);
            }

            report($e);

            return response()->json(['mensagem' => 'Serviço indisponível.'], 502);
        } catch (TransportException $e) {
            // CUIDADO: pode ter passado do lado deles. Não reenvie às cegas —
            // o job de reconciliação vai apurar o estado real.
            report($e);

            return response()->json(['mensagem' => 'Sem resposta do MKESH.'], 504);
        }

        return response()->json([
            'referencia' => $transaccao->external_transaction_id,
            'estado'     => $transaccao->status->value,
            'mensagem'   => 'Confirme o pagamento no seu telemóvel.',
        ], 202);
    }
}
```

### 6.4 Controller que recebe o callback

O ponto crítico: **o corpo da resposta tem de ser o documento `ResponseCode`.**

```php
namespace App\Http\Controllers;

use App\Models\MkeshTransaction;
use TechSolutions\Mkesh\Callback\CallbackResponse;
use TechSolutions\Mkesh\Exception\MkeshException;
use TechSolutions\Mkesh\Laravel\Facades\Mkesh;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;

final class MkeshCallbackController extends Controller
{
    public function __invoke(Request $request): Response
    {
        try {
            $callback = Mkesh::parseDebitCompleted($request->getContent());
        } catch (MkeshException) {
            return response('', 400);   // corpo inválido: não reenviem
        }

        DB::transaction(function () use ($callback): void {
            // Bloqueie a linha: os callbacks podem chegar em duplicado.
            $transaccao = MkeshTransaction::query()
                ->where('type', MkeshTransaction::TYPE_DEBIT)
                ->where('external_transaction_id', $callback->externalTransactionId)
                ->lockForUpdate()
                ->first();

            if ($transaccao === null || $transaccao->isSettled()) {
                return;   // reenvio de algo já liquidado — ignorar
            }

            $transaccao->update([
                'financial_transaction_id' => $callback->transactionId,
                'status' => $callback->status,
                'completed_at' => now(),
            ]);

            if ($callback->isSuccessful()) {
                $transaccao->payable?->marcarComoPaga();
            }
        });

        return response(CallbackResponse::success()->toXml(), 200)
            ->header('Content-Type', CallbackResponse::CONTENT_TYPE);
    }
}
```

Regras de ouro para o webhook:

- Responda sempre `SUCCESS` quando conseguir ler o corpo, mesmo que a
  transacção já esteja liquidada. Caso contrário ficam a reenviar.
- Torne-o idempotente — procure pelo `externalTransactionId` e ignore se já
  estiver liquidado.
- Bloqueie a linha (`lockForUpdate`) para dois reenvios simultâneos não
  liquidarem a mesma transacção duas vezes.
- Não faça trabalho demorado aqui. Despache um job.

### 6.5 Job de reconciliação

Cobre o ramo "sem resposta do MKESH". Ver
[`ReconcileMkeshTransaction`](examples/Laravel/ReconcileMkeshTransaction.php):

```php
ReconcileMkeshTransaction::dispatch($transaccao->id)->delay(now()->addMinutes(2));
```

Faz polling ao `gettransactionstatus` com backoff progressivo e pára assim que a
linha estiver liquidada — seja pelo callback, seja pelo próprio polling. Códigos
retentáveis (`ErrorCode::isRetryable()`, sobretudo `TRANSACTION_NOT_FOUND`, que
aqui significa "ainda não registado") libertam o job para nova tentativa.

### 6.6 Command Artisan para reconciliar em lote

```php
namespace App\Console\Commands;

use App\Jobs\ReconcileMkeshTransaction;
use App\Models\MkeshTransaction;
use TechSolutions\Mkesh\Enum\TransactionStatus;
use Illuminate\Console\Command;

final class ReconciliarMkesh extends Command
{
    protected $signature = 'mkesh:reconciliar {--minutos=10}';
    protected $description = 'Consulta o estado dos débitos ainda pendentes';

    public function handle(): int
    {
        $pendentes = MkeshTransaction::query()
            ->where('type', MkeshTransaction::TYPE_DEBIT)
            ->where('status', TransactionStatus::PENDING)
            ->where('created_at', '<', now()->subMinutes((int) $this->option('minutos')))
            ->get();

        foreach ($pendentes as $transaccao) {
            ReconcileMkeshTransaction::dispatch($transaccao->id);
        }

        $this->info("{$pendentes->count()} transacções enviadas para reconciliação.");

        return self::SUCCESS;
    }
}
```

Agende-o em `routes/console.php`:

```php
Schedule::command('mkesh:reconciliar')->everyFifteenMinutes();
```

### 6.7 Testar sem tocar na rede

Injecte um cliente PSR-18 falso — não é preciso mais nada:

```php
use TechSolutions\Mkesh\Config\MkeshConfig;
use TechSolutions\Mkesh\MkeshClient;
use GuzzleHttp\Psr7\HttpFactory;
use GuzzleHttp\Psr7\Response;

$http = new class (new Response(200, [], $xmlDeResposta)) implements \Psr\Http\Client\ClientInterface {
    public function __construct(private $resposta) {}
    public function sendRequest(\Psr\Http\Message\RequestInterface $r): \Psr\Http\Message\ResponseInterface
    {
        return $this->resposta;
    }
};

$factory = new HttpFactory();
$mkesh = new MkeshClient($config, $http, $factory, $factory);

// No teste, substitua o singleton do container:
$this->app->instance(MkeshClient::class, $mkesh);
```

---

## 7. Operações em detalhe

> Todos os ids mostrados já incluem o prefixo — aqui `ACME`, um placeholder; o
> seu vem do provedor. O pacote aplica-o automaticamente a
> `externaltransactionid` / `providertransactionid` / `referenceid`. Ver
> [secção 3.5](#35-o-prefixo-de-transacção-em-detalhe).

### 7.1 Debit request — C2B (cobrar um cliente)

```php
use TechSolutions\Mkesh\Request\DebitRequest;
use TechSolutions\Mkesh\ValueObject\Fri;
use TechSolutions\Mkesh\ValueObject\Money;

$resposta = $mkesh->debit(DebitRequest::charge(
    customerMsisdn: '258823040400',
    amount: Money::of(25),             // 25 MZN
    externalTransactionId: '000001',
));

// Forma completa, com todos os parâmetros:
$pedido = new DebitRequest(
    fromFri: Fri::msisdn('258823040400'),   // quem paga
    amount: Money::of(25, 'MZN'),
    externalTransactionId: '000001',
    toFri: null,          // omissão: serviceProviderFri da config
    referenceId: null,    // omissão: igual ao externalTransactionId
    fromMessage: null,
    toMessage: null,
);
```

**Pedido enviado** (`Content-Type: text/xml`):

```xml
<?xml version="1.0" encoding="UTF-8"?>
<ns0:debitrequest xmlns:ns0="http://www.ericsson.com/em/emm/financial/v1_1">
  <fromfri>FRI:258823040400/MSISDN</fromfri>
  <tofri>FRI:pagamKesh/USER</tofri>
  <amount>
    <amount>25</amount>
    <currency>MZN</currency>
  </amount>
  <externaltransactionid>ACME000001</externaltransactionid>
  <referenceid>ACME000001</referenceid>
</ns0:debitrequest>
```

O `referenceid` assume por omissão o valor do id externo (como na folha do
provedor) e é por ele que o `getTransactionStatus()` procura a transacção.

**Resposta (PENDING)** → `DebitResponse`:

```xml
<?xml version="1.0" encoding="UTF-8"?>
<ns0:debitresponse xmlns:ns0="http://www.ericsson.com/em/emm/financial/v1_1">
  <transactionid>3171312</transactionid>
  <status>PENDING</status>
  <approvalid>470390</approvalid>
</ns0:debitresponse>
```

```php
$resposta->transactionId;          // "3171312"
$resposta->status;                 // TransactionStatus::PENDING
$resposta->approvalId;             // "470390"
$resposta->isPending();            // true
```

### 7.2 SP transfer — B2C (pagar a um cliente)

A carteira de origem vem de `spTransferSendingFri`, que é muitas vezes uma FRI
diferente da creditada num débito (`FRI:47225552/MM` vs `FRI:pagamKesh/USER`);
se não estiver definida, usa a `serviceProviderFri`.

```php
use TechSolutions\Mkesh\Request\SpTransferRequest;

$resposta = $mkesh->transfer(SpTransferRequest::payout(
    customerMsisdn: '258823040400',
    amount: Money::of(25),
    providerTransactionId: 'XXXXX',
));
```

**Pedido enviado:**

```xml
<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<ns2:sptransferrequest xmlns:ns2="http://www.ericsson.com/em/emm/serviceprovider/v1_2/backend">
  <sendingfri>FRI:47225552/MM</sendingfri>
  <receivingfri>FRI:258823040400/MSISDN</receivingfri>
  <amount>
    <amount>25</amount>
    <currency>MZN</currency>
  </amount>
  <providertransactionid>ACMEXXXXX</providertransactionid>
  <referenceid>ACMEXXXXX</referenceid>
</ns2:sptransferrequest>
```

**Resposta** → `SpTransferResponse`:

```xml
<?xml version="1.0" encoding="UTF-8"?>
<ns0:sptransferresponse xmlns:ns0="http://www.ericsson.com/em/emm/serviceprovider/v1_2/backend">
  <transactionid>3282002</transactionid>
  <providertransactionid>ACME-XXXXXX</providertransactionid>
</ns0:sptransferresponse>
```

```php
$resposta->transactionId;            // "3282002"
$resposta->providerTransactionId;    // "ACME-XXXXXX"
```

### 7.3 Get transaction status (recuperar um resultado)

Use o `referenceid` de uma operação anterior quando não houve resposta nem
callback.

```php
$estado = $mkesh->getTransactionStatus('000001');   // prefixo aplicado
```

**Pedido enviado:**

```xml
<?xml version="1.0" encoding="UTF-8"?>
<ns0:gettransactionstatusrequest xmlns:ns0="http://www.ericsson.com/em/emm/financial/v1_3">
  <referenceid>ACME000001</referenceid>
</ns0:gettransactionstatusrequest>
```

**Resposta (SUCESSO):**

```xml
<?xml version="1.0" encoding="UTF-8"?>
<ns0:gettransactionstatusresponse xmlns:ns0="http://www.ericsson.com/em/emm/financial/v1_3">
  <financialtransactionid>3171312</financialtransactionid>
  <status>SUCCESSFUL</status>
  <providertransactionid>ACME000001</providertransactionid>
</ns0:gettransactionstatusresponse>
```

**Resposta (FALHA)** — repare que não traz `providertransactionid`:

```xml
<?xml version="1.0" encoding="UTF-8"?>
<ns0:gettransactionstatusresponse xmlns:ns0="http://www.ericsson.com/em/emm/financial/v1_3">
  <financialtransactionid>3221328</financialtransactionid>
  <status>FAILED</status>
</ns0:gettransactionstatusresponse>
```

```php
$estado->financialTransactionId;   // "3171312"
$estado->status;                   // TransactionStatus::SUCCESSFUL
$estado->providerTransactionId;    // null quando FAILED
$estado->status->isSettled();      // true — pare de consultar
```

### 7.4 Callback debit-completed

O agregador faz POST disto para o endpoint que registou junto do provedor.

**Pedido recebido:**

```xml
<?xml version="1.0" encoding="UTF-8"?>
<ns0:debitcompletedrequest xmlns:ns0="http://www.ericsson.com/em/emm/callback/v1_2">
  <transactionid>3171312</transactionid>
  <externaltransactionid>ACME000001</externaltransactionid>
  <receiverinfo>
    <fri>FRI:1360073/MM</fri>
    <msisdn>8230X04XX</msisdn>
    <language>en</language>
  </receiverinfo>
  <status>SUCCESSFUL</status>
  <communicationchannel>http-sp</communicationchannel>
  <referenceid>ACME000001</referenceid>
</ns0:debitcompletedrequest>
```

```php
$callback = $mkesh->parseDebitCompleted($corpoDoPedido);

$callback->transactionId;            // "3171312"
$callback->externalTransactionId;    // "ACME000001" — o NOSSO id
$callback->referenceId;
$callback->status;                   // TransactionStatus::SUCCESSFUL
$callback->communicationChannel;     // "http-sp"
$callback->receiver->fri;            // "FRI:1360073/MM"
$callback->receiver->msisdn;
$callback->receiver->language;       // "en"
$callback->isSuccessful();
```

**A resposta obrigatória.** Um `200` vazio não é aceite e o callback será
reenviado:

```xml
<?xml version="1.0" encoding="utf-8"?><ResponseCode>SUCCESS</ResponseCode>
```

```php
use TechSolutions\Mkesh\Callback\CallbackResponse;

$ack = CallbackResponse::success();
$ack->toXml();                   // o corpo a devolver
CallbackResponse::CONTENT_TYPE;  // "text/xml; charset=utf-8"
```

### 7.5 Callback transfer-completed (B2C)

O equivalente B2C é entregue da mesma forma:

```php
$callback = $mkesh->parseInitiateTransferCompleted($corpoDoPedido);

$callback->financialTransactionId;
$callback->externalTransactionId;
$callback->status->isSuccessful();
$callback->receiver->msisdn;
// responda com o mesmo <ResponseCode>SUCCESS</ResponseCode>
```

---

## 8. Enums

| Enum | Casos | Serve para |
|------|-------|------------|
| `Enum\TransactionStatus` | `PENDING` `SUCCESSFUL` `FAILED` `UNKNOWN` | o estado de um débito/transferência |
| `Enum\ErrorCode` | 22 códigos + `UNKNOWN` | decidir o que fazer perante uma falha |
| `Enum\CallbackResponseCode` | `SUCCESS` `FAILURE` | o corpo devolvido pelo seu webhook |
| `Enum\FriType` | `MSISDN` `USER` `MM` | construir FRIs |

Todos interpretam defensivamente: um valor desconhecido dá `UNKNOWN` em vez de
lançar excepção, para que um valor novo da plataforma nunca parta o parsing.

### 8.1 TransactionStatus

```php
use TechSolutions\Mkesh\Enum\TransactionStatus;

TransactionStatus::PENDING;      // à espera da aprovação do cliente
TransactionStatus::SUCCESSFUL;   // dinheiro movido
TransactionStatus::FAILED;
TransactionStatus::UNKNOWN;      // valor não reconhecido

// O diagrama do provedor escreve SUCCESS/FAILURE, os payloads escrevem
// SUCCESSFUL/FAILED — ambos são aceites.
TransactionStatus::fromWire('SUCCESS');    // SUCCESSFUL
TransactionStatus::fromWire('failure');    // FAILED
TransactionStatus::fromWire('seja o que for');   // UNKNOWN

$status->isPending();
$status->isSuccessful();
$status->isFailed();
$status->isSettled();   // true se SUCCESSFUL ou FAILED — pare de consultar
```

Em Eloquent, faça cast directo para o enum:

```php
protected $casts = ['status' => TransactionStatus::class];
```

### 8.2 ErrorCode

Cobre os códigos que mudam o que a aplicação *faz*, com a decisão já embutida
em vez de ficar à mercê de comparações de strings:

```php
use TechSolutions\Mkesh\Enum\ErrorCode;

$codigo = $e->code();        // ErrorCode; $e->getErrorCode() dá a string crua

$codigo->isRetryable();      // transitório — repita o mesmo pedido mais tarde
$codigo->isDuplicate();      // id já usado; consulte o estado, NÃO reenvie
$codigo->isCustomerFault();  // sem saldo / inactivo / PIN errado / expirou
$codigo->isExpired();        // a janela de aprovação passou
$codigo->isAuthFailure();    // credenciais erradas ou IP não autorizado
$codigo->description();      // do catálogo completo de 670 códigos
```

Casos disponíveis, por família:

| Família | Casos |
|---------|-------|
| Pesquisa / idempotência | `TRANSACTION_NOT_FOUND` `REFERENCE_ID_ALREADY_IN_USE` `AMBIGUOUS_REFERENCE_ID` `EXPIRED_OR_INVALID_TRANSACTION_ID` |
| Contraparte | `ACCOUNTHOLDER_WITH_FRI_NOT_FOUND` `ACCOUNTHOLDER_WITH_MSISDN_NOT_FOUND` `ACCOUNTHOLDER_NOT_ACTIVE` `AUTHORIZATION_ACCOUNTHOLDER_NOT_ACTIVE` `ACCOUNT_NOT_FOUND` |
| Dinheiro | `AUTHORIZATION_CURRENT_BALANCE_TOO_LOW` `AUTHORIZATION_MAX_TRANSFER_AMOUNT` `AUTHORIZATION_MAXIMUM_AMOUNT_ALLOWED_TO_SEND` `AUTHORIZATION_MAXIMUM_AMOUNT_ALLOWED_TO_RECEIVE` `AMOUNT_INVALID` `INVALID_CURRENCY` `CURRENCY_NOT_SUPPORTED` |
| Aprovação | `TRANSACTION_REQUEST_EXPIRED` `INCORRECT_PIN` `QUEUED_FOR_APPROVAL` `INVALID_APPROVAL_TRANSACTION_STATUS` `RETRY_FROM_BEGINNING` |
| Acesso | `AUTHORIZATION_FAILED` |

O enum não lista os 670 códigos de propósito — seria uma segunda cópia do
`ErrorCodes` sem ganho nenhum. Qualquer código fora dele dá `ErrorCode::UNKNOWN`,
enquanto `getErrorCode()` e `ErrorCodes::description()` continuam a funcionar
sobre a string crua.

### 8.3 CallbackResponseCode

```php
use TechSolutions\Mkesh\Enum\CallbackResponseCode;

CallbackResponseCode::SUCCESS;   // o único documentado pelo provedor
CallbackResponseCode::FAILURE;

CallbackResponse::of(CallbackResponseCode::SUCCESS)->toXml();
```

Na dúvida responda `SUCCESS` e reconcilie fora de banda com o
`gettransactionstatus` — o tratamento de `FAILURE` do lado da plataforma não
está especificado.

### 8.4 FriType e os value objects

```php
use TechSolutions\Mkesh\Enum\FriType;
use TechSolutions\Mkesh\ValueObject\Fri;
use TechSolutions\Mkesh\ValueObject\Money;

FriType::MSISDN;         // FRI:258823040400/MSISDN — número de telemóvel
FriType::USER;           // FRI:pagamKesh/USER      — conta de service provider
FriType::MOBILE_MONEY;   // FRI:1360073/MM          — conta mobile money

Fri::msisdn('258823040400');
Fri::user('pagamKesh');
Fri::mobileMoney('1360073');
Fri::fromString('FRI:258823040400/MSISDN');
(string) $fri;                  // "FRI:258823040400/MSISDN"

// O valor é guardado como string normalizada, para não apanhar erros de
// vírgula flutuante ao serializar o XML.
Money::of(25);                  // 25 MZN
Money::of('25.50', 'MZN');
Money::of(25.5)->amount;        // "25.5"
```

---

## 9. Erros

Erros de negócio chegam como um envelope `errorResponse` e são lançados como
`ErrorResponseException`.

```xml
<?xml version="1.0" encoding="UTF-8"?>
<ns0:errorResponse xmlns:ns0="http://www.ericsson.com/lwac" errorcode="ACCOUNTHOLDER_WITH_FRI_NOT_FOUND">
  <arguments name="fri" value="FRI:258823040420/MSISDN"/>
</ns0:errorResponse>
```

```php
use TechSolutions\Mkesh\Enum\ErrorCode;
use TechSolutions\Mkesh\Exception\ErrorResponseException;
use TechSolutions\Mkesh\Exception\MkeshException;

try {
    $mkesh->debit($pedido);
} catch (ErrorResponseException $e) {
    $e->getErrorCode();       // "ACCOUNTHOLDER_WITH_FRI_NOT_FOUND"
    $e->getDescription();     // "Account holder with given FRI could not be found"
    $e->getArguments();       // ["fri" => "FRI:258823040420/MSISDN"]
    $e->getArgument('fri');
    $e->getRawXml();          // corpo original, para auditoria
    $e->code();               // ErrorCode (ver secção 8.2)

    // is() aceita enum ou string, indiferentemente
    $e->is(ErrorCode::TRANSACTION_NOT_FOUND);
    $e->is('TRANSACTION_NOT_FOUND');
    $e->is(ErrorCode::AMOUNT_INVALID, ErrorCode::INVALID_CURRENCY);
} catch (MkeshException $e) {
    // qualquer outra falha do pacote (transporte, config, parsing…)
}
```

Hierarquia — todas implementam a interface marcadora `MkeshException`:

| Excepção | Lançada quando |
|----------|----------------|
| `ErrorResponseException` | a plataforma devolveu um `<errorResponse>` |
| `TransportException` | falha de ligação/TLS/timeout, corpo vazio ou XML inválido |
| `ConfigurationException` | configuração em falta ou inválida |
| `InvalidArgumentException` | value object ou input de pedido inválido |

### Catálogo completo de códigos

Os **670 códigos** da referência da plataforma estão em
`TechSolutions\Mkesh\Error\ErrorCodes`, e a descrição é acrescentada
automaticamente à mensagem da excepção:

```php
use TechSolutions\Mkesh\Error\ErrorCodes;

ErrorCodes::description('TRANSACTION_NOT_FOUND');
ErrorCodes::has('REFERENCE_ID_ALREADY_IN_USE');
ErrorCodes::all();     // array<string, string>
```

### Os três erros que vai mesmo encontrar

| Código | Significado | O que fazer |
|--------|-------------|-------------|
| `REFERENCE_ID_ALREADY_IN_USE` | id repetido | O original quase de certeza passou. Consulte o estado — **não** reenvie com id novo. |
| `TRANSACTION_NOT_FOUND` | ainda não registado | Consultou cedo demais. Repita mais tarde. |
| `ACCOUNTHOLDER_WITH_FRI_NOT_FOUND` | número não é mKesh | Valide o MSISDN antes de cobrar. |

---

## 10. Base de dados

Duas migrations acompanham o pacote e correm com `php artisan migrate`.

As duas tabelas usam **UUID como chave primária** (`$table->uuid('id')->primary()`),
e a ligação `mkesh_responses.mkesh_transaction_id` é uma `foreignUuid`. Os models
têm de usar o trait `HasUuids`, que gera o id na criação e ajusta o tipo de chave:

```php
use Illuminate\Database\Eloquent\Concerns\HasUuids;

class MkeshTransaction extends Model
{
    use HasUuids;
}
```

Os models de exemplo em [`examples/Laravel/`](examples/Laravel/) já vêm assim. O
`payable_type` / `payable_id` continua a ser `nullableMorphs`, porque a chave é a
do **seu** model — se as suas entidades também usarem UUID, troque por
`$table->nullableUuidMorphs('payable')`.

### `mkesh_transactions` — o livro-razão

A linha é criada **antes** do pedido sair, para reservar o id localmente.

| Coluna | Notas |
|--------|-------|
| `id` | UUID, gerado pelo `HasUuids` |
| `type` | `debit` (C2B) ou `transfer` (B2C) |
| `external_transaction_id` | enviado no débito, único por tipo |
| `provider_transaction_id` | enviado na transferência, único por tipo |
| `reference_id` | o que o `getTransactionStatus()` procura |
| `financial_transaction_id` | devolvido pela plataforma |
| `approval_id` | de um débito pendente |
| `msisdn`, `amount`, `currency` | contraparte e dinheiro |
| `status` | `PENDING` / `SUCCESSFUL` / `FAILED` / `UNKNOWN` |
| `error_code`, `error_message` | código da plataforma + descrição do catálogo |
| `payable_type`, `payable_id` | ligação polimórfica à sua encomenda/factura |
| `completed_at` | quando liquidou |

### `mkesh_responses` — a auditoria

Propositadamente **não** é única por transacção, porque os callbacks são
reenviados. É precisamente isso que a torna útil: prova o que chegou, quando, e
o que respondeu.

| Coluna | Notas |
|--------|-------|
| `id` | UUID, gerado pelo `HasUuids` |
| `direction` | `inbound` (callback recebido) / `outbound` (resposta a pedido nosso) |
| `operation` | `debitcompletedrequest`, `sptransferresponse`, `errorResponse`… |
| `mkesh_transaction_id` | ligação ao livro-razão, quando houve correspondência |
| `external_transaction_id`, `reference_id`, `financial_transaction_id` | desnormalizados para pesquisa |
| `status`, `error_code` | extraídos para consulta |
| `response_code` | o `SUCCESS` / `FAILURE` que devolvemos |
| `http_status` | código HTTP devolvido ou recebido |
| `payload` | o XML intacto |

```php
$transaccao->responses;              // tudo o que o MKESH disse sobre ela
$resposta->transaction;              // relação inversa
```

---

## 11. TLS, IP de origem e cliente HTTP

### TLS

O agregador está publicado em HTTPS sobre um **IP nu**, por isso o certificado
não corresponde ao hostname e a verificação por omissão falha. A verificação
está **ligada** por omissão — aponte `MKESH_SSL_CA_BUNDLE` para o certificado
que o provedor fornecer e só desligue (`MKESH_VERIFY_SSL=false`) contra o
ambiente de testes.

### IP de origem

O provedor faz **whitelist do IP de origem**: as chamadas têm de sair do host
que registou junto deles. Uma máquina local ou um IP de saída diferente é
rejeitado ao nível da rede, antes de qualquer XML ser interpretado.

### Cliente HTTP personalizado

`MkeshClient::create()` liga o Guzzle por si. Para usar outro cliente PSR-18,
injecte-o (com as factories PSR-17) pelo construtor:

```php
$client = new MkeshClient($config, $psr18Client, $requestFactory, $streamFactory);
```

---

## 12. Testes e resolução de problemas

```bash
composer test
```

### Teste manual pela linha de comandos

```bash
php examples/mkesh-test.php debit    258823040400 25
php examples/mkesh-test.php transfer 258823040400 10
php examples/mkesh-test.php status   000001
```

Imprime o XML enviado e a resposta interpretada — é a forma mais rápida de
resolver um desacordo com o provedor.

### Problemas comuns

| Sintoma | Causa provável |
|---------|----------------|
| O callback chega repetidamente | Não está a devolver `<ResponseCode>SUCCESS</ResponseCode>`. Um `200` vazio não chega. |
| `REFERENCE_ID_ALREADY_IN_USE` no primeiro envio | O id foi reutilizado. Use `newTransactionId()` e grave-o antes de enviar. |
| `TRANSACTION_NOT_FOUND` ao consultar | Consultou cedo demais, ou usou o id errado — a procura é pelo `referenceid`. |
| `AUTHORIZATION_FAILED` | Credenciais erradas **ou** IP de origem não autorizado. |
| Erro de certificado TLS | Host num IP nu. Configure `MKESH_SSL_CA_BUNDLE`. |
| Timeout sem resposta | Pode ter passado do lado deles. Consulte o estado antes de reenviar. |
| Débito fica sempre `PENDING` | O cliente não aprovou. O pedido expira; veja `TRANSACTION_REQUEST_EXPIRED`. |

---

## Créditos

Este pacote é um projecto da **[TechSolutions](https://github.com/TECHSOLUTIONS-PROJECTS)**.

Desenvolvido por **[Osvaldo Geraldo Manjate](https://github.com/osvaldogeraldo)**,
enquanto colaborador da TechSolutions.

---

## Licença

MIT — © 2026 TechSolutions. Ver [LICENSE](LICENSE).

- Repositório: <https://github.com/TECHSOLUTIONS-PROJECTS/mkesh>
- Geral: **info@techsolutions.co.mz**
- Suporte técnico: **it@techsolutions.co.mz**
