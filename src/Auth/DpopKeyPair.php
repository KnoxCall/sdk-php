<?php

declare(strict_types=1);

namespace KnoxCall\Auth;

/**
 * DPoP proof generation (RFC 9449) — client side. PHP port of
 * knoxcall-node/src/auth/dpop.ts (see ../../PARITY.md §7).
 *
 * Generates an ES256 (P-256) keypair and signs a fresh proof JWT per
 * request (new jti/iat every call, htu stripped of query and fragment,
 * ath bound to the access token when one is presented). Requires
 * ext-openssl. The private key never leaves the process.
 */
final class DpopKeyPair
{
    /** @var array{crv: string, kty: string, x: string, y: string} */
    private readonly array $publicJwk;

    private function __construct(
        private readonly \OpenSSLAsymmetricKey $privateKey,
        array $publicJwk,
    ) {
        $this->publicJwk = $publicJwk;
    }

    /** Generate a fresh ES256 keypair. */
    public static function generate(): self
    {
        $key = openssl_pkey_new([
            'curve_name' => 'prime256v1',
            'private_key_type' => OPENSSL_KEYTYPE_EC,
        ]);
        if ($key === false) {
            throw new \RuntimeException('knoxcall: failed to generate DPoP keypair: ' . (string) openssl_error_string());
        }
        $details = openssl_pkey_get_details($key);
        if ($details === false || !isset($details['ec']['x'], $details['ec']['y'])) {
            throw new \RuntimeException('knoxcall: could not read DPoP public key details');
        }
        // Coordinates are big-endian binary and may be shorter than 32 bytes.
        $x = str_pad($details['ec']['x'], 32, "\0", STR_PAD_LEFT);
        $y = str_pad($details['ec']['y'], 32, "\0", STR_PAD_LEFT);
        return new self($key, [
            'crv' => 'P-256',
            'kty' => 'EC',
            'x' => self::b64url($x),
            'y' => self::b64url($y),
        ]);
    }

    /**
     * Sign a DPoP proof JWT for one request (RFC 9449 §4.2). The signature
     * is ECDSA P-256 + SHA-256 in JOSE P1363 form (r||s, 64 bytes), matching
     * the server verifier in src/lib/dpop-verifier.ts.
     */
    public function sign(string $method, string $url, ?string $accessToken = null, ?string $nonce = null): string
    {
        $htu = explode('#', $url, 2)[0];
        $htu = explode('?', $htu, 2)[0];

        $header = ['alg' => 'ES256', 'typ' => 'dpop+jwt', 'jwk' => $this->publicJwk];
        $payload = [
            'htm' => strtoupper($method),
            'htu' => $htu,
            'iat' => time(),
            'jti' => self::b64url(random_bytes(16)),
        ];
        if ($accessToken !== null && $accessToken !== '') {
            $payload['ath'] = self::b64url(hash('sha256', $accessToken, true));
        }
        if ($nonce !== null && $nonce !== '') {
            $payload['nonce'] = $nonce;
        }

        $signingInput = self::b64urlJson($header) . '.' . self::b64urlJson($payload);
        $der = '';
        if (!openssl_sign($signingInput, $der, $this->privateKey, OPENSSL_ALGO_SHA256)) {
            throw new \RuntimeException('knoxcall: failed to sign DPoP proof: ' . (string) openssl_error_string());
        }
        return $signingInput . '.' . self::b64url(self::derToP1363($der));
    }

    /**
     * RFC 7638 JWK thumbprint of the public key — the value the server
     * binds tokens to as cnf.jkt. Canonical form is the JSON object with
     * exactly the members crv, kty, x, y in that order, which is the
     * insertion order of $publicJwk.
     */
    public function thumbprint(): string
    {
        $canonical = json_encode($this->publicJwk, JSON_UNESCAPED_SLASHES);
        return self::b64url(hash('sha256', (string) $canonical, true));
    }

    private static function b64url(string $bytes): string
    {
        return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
    }

    /** @param array<string, mixed> $value */
    private static function b64urlJson(array $value): string
    {
        return self::b64url((string) json_encode($value, JSON_UNESCAPED_SLASHES));
    }

    /** Convert an ASN.1/DER ECDSA signature to JOSE P1363 (r||s, 64 bytes). */
    private static function derToP1363(string $der): string
    {
        if ($der === '' || ord($der[0]) !== 0x30) {
            throw new \RuntimeException('knoxcall: invalid DER signature');
        }
        $i = 2;
        if (ord($der[1]) & 0x80) { // long-form length
            $i += ord($der[1]) & 0x7F;
        }
        $read = static function (string $der, int &$i): string {
            if (ord($der[$i]) !== 0x02) {
                throw new \RuntimeException('knoxcall: invalid DER signature');
            }
            $len = ord($der[$i + 1]);
            $value = substr($der, $i + 2, $len);
            $i += 2 + $len;
            $value = ltrim($value, "\0");
            if (strlen($value) > 32) {
                throw new \RuntimeException('knoxcall: invalid DER signature');
            }
            return str_pad($value, 32, "\0", STR_PAD_LEFT);
        };
        $r = $read($der, $i);
        $s = $read($der, $i);
        return $r . $s;
    }
}
