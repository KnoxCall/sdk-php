<?php

declare(strict_types=1);

namespace KnoxCall\Resources;

use KnoxCall\KnoxCall;
use KnoxCall\KnoxCallException;

class PkiResource
{
    use UnwrapsEnvelope;

    public function __construct(private readonly KnoxCall $client) {}

    /** @return list<array<string, mixed>> bare array of CA roots — no pagination */
    public function listRoots(): array
    {
        return self::data($this->client->request('GET', '/v1/pki/roots'));
    }

    /** @return array{root: array<string, mixed>, intermediate_not_after: string} */
    public function createRoot(string $name, array $subject): array
    {
        return self::data($this->client->request('POST', '/v1/pki/roots', null, ['name' => $name, 'subject' => $subject]));
    }

    /** Raw PEM text (text/x-pem-file) — no JSON wrapper. */
    public function getRootCert(string $name): string
    {
        return self::rawText($this->client->request('GET', '/v1/pki/roots/' . rawurlencode($name) . '/cert'), 'CA certificate');
    }

    /** @return array{intermediate_id: string, not_after: string} */
    public function rotateIntermediate(string $name): array
    {
        return self::data($this->client->request('POST', '/v1/pki/roots/' . rawurlencode($name) . '/rotate-intermediate', null, []));
    }

    /** Raw CRL text — no JSON wrapper. */
    public function getCrl(string $name): string
    {
        return self::rawText($this->client->request('GET', '/v1/pki/roots/' . rawurlencode($name) . '/crl'), 'CRL');
    }

    /** @return list<array<string, mixed>> bare array of roles — no pagination */
    public function listRoles(string $rootName): array
    {
        return self::data($this->client->request('GET', '/v1/pki/roots/' . rawurlencode($rootName) . '/roles'));
    }

    public function createRole(string $rootName, array $input): array
    {
        return self::data($this->client->request('POST', '/v1/pki/roots/' . rawurlencode($rootName) . '/roles', null, $input));
    }

    /**
     * Issue a leaf certificate under a role. The `private_key_pem` is shown
     * exactly once — store it now.
     *
     * @return array{serial_hex: string, cert_pem: string, private_key_pem: string, ca_chain_pem: string, not_before: string, not_after: string}
     */
    public function issueCert(string $rootName, string $role, array $input = []): array
    {
        return self::data($this->client->request(
            'POST',
            '/v1/pki/roots/' . rawurlencode($rootName) . '/issue/' . rawurlencode($role),
            null,
            $input,
        ));
    }

    /** @return array{revoked: bool} */
    public function revokeCert(string $rootName, string $serialHex, string $reason = ''): array
    {
        $body = array_filter(['serial_hex' => $serialHex, 'reason' => $reason], fn($v) => $v !== '');
        return self::data($this->client->request('POST', '/v1/pki/roots/' . rawurlencode($rootName) . '/revoke', null, $body));
    }

    /** These two endpoints return raw text, not the JSON envelope. */
    private static function rawText(mixed $response, string $what): string
    {
        if (!is_string($response)) {
            throw new KnoxCallException("expected raw {$what} text but got a JSON response");
        }
        return $response;
    }
}
