# Changelog

Todas as alterações relevantes deste pacote são registadas aqui.

O formato segue [Keep a Changelog](https://keepachangelog.com/pt-BR/1.1.0/) e o
versionamento segue [SemVer](https://semver.org/lang/pt-BR/).

## [Não lançado]

### Alterado

- As tabelas `mkesh_transactions` e `mkesh_responses` passam a usar **UUID como
  chave primária** (`$table->uuid('id')->primary()`), e a ligação entre elas é
  agora uma `foreignUuid`. Os models de exemplo usam o trait `HasUuids` e o job
  `ReconcileMkeshTransaction` recebe o id como `string`.

## [1.0.0] — 2026-07-23

Primeira versão pública, alinhada com a folha de integração
"Integration MTL_EWP_Agregator" e o diagrama "C2B Flow" do provedor.

### Operações

- `debit()` — débito C2B (debitrequest v1_1)
- `transfer()` — pagamento B2C (sptransferrequest v1_2)
- `getTransactionStatus()` — consulta de estado (gettransactionstatusrequest v1_3)
- `parseDebitCompleted()` — callback C2B (debitcompletedrequest v1_2)
- `parseInitiateTransferCompleted()` — callback B2C
- `acknowledgeCallback()` — corpo `<ResponseCode>SUCCESS</ResponseCode>`

### Enums

- `TransactionStatus` — com `isSettled()` e tolerância a sinónimos
  (`SUCCESS`/`SUCCESSFUL`, `FAILURE`/`FAILED`)
- `ErrorCode` — 22 códigos da plataforma com classificação pronta
  (`isRetryable`, `isDuplicate`, `isCustomerFault`, `isExpired`, `isAuthFailure`)
- `CallbackResponseCode` — `SUCCESS` / `FAILURE`
- `FriType` — `MSISDN` / `USER` / `MM`

### Laravel

- Auto-discovery do `MkeshServiceProvider` e da facade `Mkesh`
- Configuração publicável (`--tag=mkesh-config`)
- Migrations `mkesh_transactions` e `mkesh_responses`, executadas directamente
  ou publicáveis (`--tag=mkesh-migrations`)
- Exemplos prontos a copiar: model, serviço, controller de callback e job de
  reconciliação

### Notas de integração

- O `referenceid` passa a ser sempre enviado, assumindo por omissão o valor do
  `externaltransactionid` — é por ele que a consulta de estado procura a
  transacção.
- O `<callbackurl>` **não** é enviado no payload do débito: o endpoint é
  registado do lado do provedor. Active `sendCallbackUrl` se a sua instância
  aceitar a sobreposição por pedido.
- A carteira de origem dos pagamentos B2C é configurável em separado
  (`sp_transfer_sending_fri`), por ser diferente da conta creditada num débito.
- A ordem dos elementos XML segue exactamente a dos payloads documentados.

[Não lançado]: https://github.com/TECHSOLUTIONS-PROJECTS/mkesh/compare/v1.0.0...HEAD
[1.0.0]: https://github.com/TECHSOLUTIONS-PROJECTS/mkesh/releases/tag/v1.0.0
