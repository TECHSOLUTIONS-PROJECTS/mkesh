<?php

declare(strict_types=1);

namespace BrilliantMind\Mkesh\Config;

use BrilliantMind\Mkesh\Exception\ConfigurationException;
use BrilliantMind\Mkesh\ValueObject\Fri;

/**
 * Immutable configuration for the MKESH / EWP aggregator connection.
 *
 * Holds credentials, the service-provider FRI, default currency, the
 * transaction-id prefix mandated by the aggregator and the per-operation
 * endpoints. Endpoints default to the aggregator paths documented in the
 * integration spec but can be overridden (e.g. for a staging host).
 */
final class MkeshConfig
{
    public readonly Fri $serviceProviderFri;

    /**
     * The wallet money is sent *from* on a B2C sptransfer. The integration
     * sheet uses a different FRI here than the debit credit account
     * (sendingfri "FRI:47225552/MM" vs tofri "FRI:pagamKesh/USER"), so it is
     * configured separately and falls back to {@see $serviceProviderFri}.
     */
    public readonly Fri $spTransferSendingFri;

    /**
     * @param string      $username           HTTP Basic auth username.
     * @param string      $password           HTTP Basic auth password.
     * @param Fri|string  $serviceProviderFri The SP wallet FRI credited on a debit, e.g. "FRI:pagamKesh/USER".
     * @param string      $baseUrl            Base aggregator URL, e.g. "https://41.220.193.151".
     * @param string      $defaultCurrency    Currency applied to amounts when not given (e.g. "MZN").
     * @param string|null $transactionPrefix  Mandatory prefix the aggregator requires on
     *                                         externaltransactionid / referenceid values (e.g. "MTL").
     * @param string|null $callbackUrl         The debitcompleted callback endpoint. Registered with the
     *                                         aggregator out of band — see {@see $sendCallbackUrl}.
     * @param bool        $verifySsl           Whether to verify the TLS certificate.
     * @param string|null $sslCaBundle         Optional path to a CA bundle used for verification.
     * @param float       $timeout             Per-request timeout in seconds.
     * @param string      $debitPath           Path for debitrequest (C2B).
     * @param string      $spTransferPath      Path for sptransfer (B2C).
     * @param string      $transactionStatusPath Path for gettransactionstatus.
     * @param Fri|string|null $spTransferSendingFri Sending wallet for B2C payouts. Defaults to $serviceProviderFri.
     * @param bool        $sendCallbackUrl     Emit <callbackurl> inside the debitrequest. Off by default:
     *                                         the aggregator forwards debitcompleted to the endpoint the
     *                                         partner registered with it, and the documented payload has
     *                                         no such element. Only turn this on if your aggregator
     *                                         instance accepts a per-request override.
     */
    public function __construct(
        public readonly string $username,
        public readonly string $password,
        Fri|string $serviceProviderFri,
        public readonly string $baseUrl = 'https://41.220.193.151',
        public readonly string $defaultCurrency = 'MZN',
        public readonly ?string $transactionPrefix = null,
        public readonly ?string $callbackUrl = null,
        public readonly bool $verifySsl = true,
        public readonly ?string $sslCaBundle = null,
        public readonly float $timeout = 30.0,
        public readonly string $debitPath = '/DebitServlet/DebitSvlt',
        public readonly string $spTransferPath = '/sptransfer/sptransfer',
        public readonly string $transactionStatusPath = '/GetTransactionStatus/GetStatusSvlt',
        Fri|string|null $spTransferSendingFri = null,
        public readonly bool $sendCallbackUrl = false,
    ) {
        if (trim($username) === '') {
            throw new ConfigurationException('MKESH username is required.');
        }
        if (trim($baseUrl) === '') {
            throw new ConfigurationException('MKESH base URL is required.');
        }

        $this->serviceProviderFri = $serviceProviderFri instanceof Fri
            ? $serviceProviderFri
            : Fri::fromString($serviceProviderFri);

        $this->spTransferSendingFri = match (true) {
            $spTransferSendingFri instanceof Fri => $spTransferSendingFri,
            is_string($spTransferSendingFri) && trim($spTransferSendingFri) !== '' => Fri::fromString($spTransferSendingFri),
            default => $this->serviceProviderFri,
        };
    }

    /**
     * Build from an associative array (e.g. a Laravel config entry).
     *
     * @param array<string, mixed> $config
     */
    public static function fromArray(array $config): self
    {
        $fri = $config['service_provider_fri'] ?? $config['serviceProviderFri'] ?? null;
        if ($fri === null) {
            throw new ConfigurationException('MKESH service provider FRI is required.');
        }

        return new self(
            username: (string) ($config['username'] ?? ''),
            password: (string) ($config['password'] ?? ''),
            serviceProviderFri: $fri,
            baseUrl: (string) ($config['base_url'] ?? $config['baseUrl'] ?? 'https://41.220.193.151'),
            defaultCurrency: (string) ($config['default_currency'] ?? $config['defaultCurrency'] ?? 'MZN'),
            transactionPrefix: self::nullableString($config['transaction_prefix'] ?? $config['transactionPrefix'] ?? null),
            callbackUrl: self::nullableString($config['callback_url'] ?? $config['callbackUrl'] ?? null),
            verifySsl: (bool) ($config['verify_ssl'] ?? $config['verifySsl'] ?? true),
            sslCaBundle: self::nullableString($config['ssl_ca_bundle'] ?? $config['sslCaBundle'] ?? null),
            timeout: (float) ($config['timeout'] ?? 30.0),
            debitPath: (string) ($config['debit_path'] ?? $config['debitPath'] ?? '/DebitServlet/DebitSvlt'),
            spTransferPath: (string) ($config['sp_transfer_path'] ?? $config['spTransferPath'] ?? '/sptransfer/sptransfer'),
            transactionStatusPath: (string) ($config['transaction_status_path'] ?? $config['transactionStatusPath'] ?? '/GetTransactionStatus/GetStatusSvlt'),
            spTransferSendingFri: self::nullableString($config['sp_transfer_sending_fri'] ?? $config['spTransferSendingFri'] ?? null),
            sendCallbackUrl: (bool) ($config['send_callback_url'] ?? $config['sendCallbackUrl'] ?? false),
        );
    }

    public function debitUrl(): string
    {
        return $this->url($this->debitPath);
    }

    public function spTransferUrl(): string
    {
        return $this->url($this->spTransferPath);
    }

    public function transactionStatusUrl(): string
    {
        return $this->url($this->transactionStatusPath);
    }

    /**
     * Apply the configured transaction prefix to an id, unless it already
     * carries it. Returns the id unchanged when no prefix is configured.
     */
    public function applyPrefix(string $id): string
    {
        if ($this->transactionPrefix === null || $this->transactionPrefix === '') {
            return $id;
        }

        if (str_starts_with($id, $this->transactionPrefix)) {
            return $id;
        }

        return $this->transactionPrefix . $id;
    }

    /**
     * Generate a fresh, spec-compliant transaction id: the configured prefix
     * followed by a unique, URL-safe suffix.
     *
     * The aggregator rejects a reused id with REFERENCE_ID_ALREADY_IN_USE, so
     * ids must be unique per service provider — persist the value you generate
     * before sending the request.
     */
    public function newTransactionId(?string $suffix = null): string
    {
        $suffix ??= strtoupper(bin2hex(random_bytes(8)));

        return $this->applyPrefix($suffix);
    }

    private function url(string $path): string
    {
        return rtrim($this->baseUrl, '/') . '/' . ltrim($path, '/');
    }

    private static function nullableString(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        return (string) $value;
    }
}
