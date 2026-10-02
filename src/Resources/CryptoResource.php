<?php

declare(strict_types=1);

namespace KnoxCall\Resources;

use KnoxCall\KnoxCall;

class CryptoResource
{
    use UnwrapsEnvelope;

    public function __construct(private readonly KnoxCall $client) {}

    // -- Key management --

    /** @return list<array<string, mixed>> bare array — no pagination */
    public function listKeys(): array
    {
        return self::data($this->client->request('GET', '/v1/crypto/keys'));
    }

    public function getKey(string $name): array
    {
        return self::data($this->client->request('GET', '/v1/crypto/keys/' . rawurlencode($name)));
    }

    public function createKey(array $input): array
    {
        return self::data($this->client->request('POST', '/v1/crypto/keys', null, $input));
    }

    /**
     * Raise or lower the key's destroy safety latch (`deletion_allowed`).
     *
     * A version can only be destroyed while this is true, and every new key
     * ships with it false. destroyKeyVersion() on a key with the latch down is
     * refused with a 409 (deletion_not_allowed) — a client error, never retry
     * it unchanged. Raise the latch, destroy, then lower it again.
     *
     * @return array{name: string, deletion_allowed: bool}
     */
    public function updateKey(string $name, bool $deletionAllowed): array
    {
        return self::data($this->client->request(
            'PATCH',
            '/v1/crypto/keys/' . rawurlencode($name),
            null,
            ['deletion_allowed' => $deletionAllowed],
        ));
    }

    /** @return array{new_version: int} */
    public function rotateKey(string $name): array
    {
        return self::data($this->client->request('POST', '/v1/crypto/keys/' . rawurlencode($name) . '/rotate', null, []));
    }

    /** @return array{destroyed: int} the destroyed version */
    public function destroyKeyVersion(string $name, int $version): array
    {
        return self::data($this->client->request('DELETE', '/v1/crypto/keys/' . rawurlencode($name) . '/versions/' . $version));
    }

    /** @return array{pem: string, jwk: array<string, mixed>, key_version: int} */
    public function getPublicKey(string $name, ?int $version = null): array
    {
        return self::data($this->client->request(
            'GET',
            '/v1/crypto/keys/' . rawurlencode($name) . '/public-key',
            $version !== null ? ['version' => $version] : null,
        ));
    }

    // -- Encryption --

    /** @return array{ciphertext: string, key_version: int} */
    public function encrypt(string $name, array $input): array
    {
        return self::data($this->client->request('POST', '/v1/crypto/keys/' . rawurlencode($name) . '/encrypt', null, $input));
    }

    /**
     * Returns plaintext_b64 by default; pass $format = 'utf8' for a decoded
     * `plaintext` string instead.
     *
     * @return array{plaintext_b64?: string, plaintext?: string, key_version: int}
     */
    public function decrypt(string $name, string $ciphertext, ?string $format = null): array
    {
        return self::data($this->client->request(
            'POST',
            '/v1/crypto/keys/' . rawurlencode($name) . '/decrypt',
            $format !== null ? ['format' => $format] : null,
            ['ciphertext' => $ciphertext],
        ));
    }

    /** @return array{ciphertext: string, key_version: int} */
    public function rewrap(string $name, string $ciphertext): array
    {
        return self::data($this->client->request('POST', '/v1/crypto/keys/' . rawurlencode($name) . '/rewrap', null, ['ciphertext' => $ciphertext]));
    }

    // -- Portable kc: encryption (structure-preserving, top-level /v1) --
    // Distinct from the keyed transit encrypt() above: these take arbitrary
    // JSON and return the same shape with scalar leaves swapped for portable,
    // self-describing `kc:` ciphertext strings. Backed by ecdh-p256 keys.

    /**
     * @param array{key?: string, role?: string} $opts
     * @return array{ciphertext: mixed, key: string, key_version: int}
     */
    public function encryptData(mixed $data, array $opts = []): array
    {
        $body = ['data' => $data];
        if (isset($opts['key'])) {
            $body['key'] = $opts['key'];
        }
        if (isset($opts['role'])) {
            $body['role'] = $opts['role'];
        }
        return self::data($this->client->request('POST', '/v1/encrypt', null, $body));
    }

    /**
     * @param array{role?: string} $opts
     * @return array{plaintext: mixed}
     */
    public function decryptData(mixed $data, array $opts = []): array
    {
        $body = ['data' => $data];
        if (isset($opts['role'])) {
            $body['role'] = $opts['role'];
        }
        return self::data($this->client->request('POST', '/v1/decrypt', null, $body));
    }

    /**
     * Metadata about a single `kc:` ciphertext string — no decryption.
     *
     * @return array{encrypted: bool, scheme?: string, version?: int, datatype?: string, key_ref?: array<string, mixed>, fingerprint?: string}
     */
    public function inspect(string $value): array
    {
        return self::data($this->client->request('POST', '/v1/inspect', null, ['value' => $value]));
    }

    /**
     * Mint a single-use, payload-pinned client-side capability token. Hand the
     * returned `token` to a browser/agent so it can reveal exactly the bound
     * `data` (a kc: ciphertext for `decrypt`, a vault token for `detokenize`)
     * once, via POST /v1/client/{decrypt,detokenize}, without an API key.
     *
     * @param array{action: string, data: string, role?: string, ttl_seconds?: int} $input
     * @return array{token: string, expires_at: string, action: string}
     */
    public function mintClientToken(array $input): array
    {
        return self::data($this->client->request('POST', '/v1/client-tokens', null, $input));
    }

    /**
     * The public bits a browser needs to seal values client-side (public key
     * + key_ref). Your backend calls this and hands the JSON to the page; no
     * private material is included.
     */
    public function getSealingBundle(?string $key = null): array
    {
        return self::data($this->client->request('GET', '/v1/encrypt/sealing-bundle', $key !== null ? ['key' => $key] : null));
    }

    // -- Signing --

    /** @return array{signature: string, key_version: int} */
    public function sign(string $name, array $input): array
    {
        return self::data($this->client->request('POST', '/v1/crypto/keys/' . rawurlencode($name) . '/sign', null, $input));
    }

    /** @return array{valid: bool, key_version: int} */
    public function verify(string $name, array $input): array
    {
        return self::data($this->client->request('POST', '/v1/crypto/keys/' . rawurlencode($name) . '/verify', null, $input));
    }

    // -- JWT --

    /** @return array{token: string, key_version: int, alg: string} */
    public function signJwt(string $name, array $claims, array $headerOverrides = []): array
    {
        $body = ['claims' => $claims];
        if ($headerOverrides) {
            $body['header_overrides'] = $headerOverrides;
        }
        return self::data($this->client->request('POST', '/v1/crypto/keys/' . rawurlencode($name) . '/jwt', null, $body));
    }

    /**
     * @param array{iss?: string, aud?: string, sub?: string, clock_skew_seconds?: int} $expected
     * @return array{valid: bool, claims?: array<string, mixed>, key_version?: int, alg?: string, error?: string, kid?: string}
     */
    public function verifyJwt(string $name, string $token, array $expected = []): array
    {
        $body = array_filter(['token' => $token, 'expected' => $expected ?: null], fn($v) => $v !== null);
        return self::data($this->client->request('POST', '/v1/crypto/keys/' . rawurlencode($name) . '/jwt/verify', null, $body));
    }

    // -- Webhook signing --

    /** @return array{signature_header: string, timestamp_seconds: int, key_version: int, format: string} */
    public function signWebhook(string $name, array $input): array
    {
        return self::data($this->client->request('POST', '/v1/crypto/keys/' . rawurlencode($name) . '/webhook-sign', null, $input));
    }
}
