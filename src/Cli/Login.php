<?php

declare(strict_types=1);

namespace KnoxCall\Cli;

use KnoxCall\Auth\CredentialsFile;
use KnoxCall\Transport\TransportInterface;

/**
 * `knoxcall login` — auth-code+PKCE (S256 only) via a loopback redirect, or
 * the RFC 8628 device flow (`--device` / `--no-browser`). Mirrors
 * knoxcall-python cli/login.py: same flows, messages, and exit codes.
 */
final class Login
{
    private const DEVICE_GRANT = 'urn:ietf:params:oauth:grant-type:device_code';

    /** @var resource */
    private $out;

    public function __construct(
        private readonly TransportInterface $transport,
        /** sleeper — injected by tests to assert the device-poll cadence */
        private readonly \Closure $sleep,
        /** browser opener — the URL is ALWAYS printed regardless */
        private readonly \Closure $openBrowser,
        $out,
    ) {
        $this->out = $out;
    }

    // -- PKCE (RFC 7636, S256 only) ---------------------------------------------

    /** @return array{0: string, 1: string} [code_verifier, code_challenge] — unpadded base64url */
    public static function generatePkcePair(): array
    {
        $verifier = self::base64url(random_bytes(48));
        $challenge = self::base64url(hash('sha256', $verifier, true));
        return [$verifier, $challenge];
    }

    private static function base64url(string $bytes): string
    {
        return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
    }

    public static function buildAuthorizeUrl(
        string $baseUrl,
        string $redirectUri,
        string $state,
        string $codeChallenge,
        ?string $tenant = null,
    ): string {
        $params = [
            'response_type' => 'code',
            'client_id' => Common::CLI_CLIENT_ID,
            'redirect_uri' => $redirectUri,
            'state' => $state,
            'code_challenge' => $codeChallenge,
            'code_challenge_method' => 'S256',
        ];
        if ($tenant !== null && $tenant !== '') {
            $params['tenant'] = $tenant;
        }
        return "{$baseUrl}/oauth/authorize?" . http_build_query($params);
    }

    /**
     * Best-effort per-OS browser launch (`start` / `open` / `xdg-open`).
     * Callers print the URL FIRST — a broken launcher is never fatal.
     */
    public static function openSystemBrowser(string $url): void
    {
        if (PHP_OS_FAMILY === 'Windows') {
            // `start` is a cmd builtin; the empty "" fills its window-title
            // slot. Manual double quotes: escapeshellarg() on Windows mangles
            // the %XX percent-encoding, and http_build_query output never
            // contains a double quote.
            @exec('cmd /c start "" "' . $url . '" >NUL 2>&1');
        } elseif (PHP_OS_FAMILY === 'Darwin') {
            @exec('open ' . escapeshellarg($url) . ' > /dev/null 2>&1 &');
        } else {
            @exec('xdg-open ' . escapeshellarg($url) . ' > /dev/null 2>&1 &');
        }
    }

    // -- Flows -------------------------------------------------------------------

    /** @return array<string, mixed> the token response body */
    public function authCodeFlow(string $baseUrl, ?string $tenant = null, float $timeout = 300.0): array
    {
        [$verifier, $challenge] = self::generatePkcePair();
        $state = self::base64url(random_bytes(24));
        $server = new LoopbackServer();
        try {
            $redirectUri = "http://127.0.0.1:{$server->port}/callback";
            $url = self::buildAuthorizeUrl($baseUrl, $redirectUri, $state, $challenge, $tenant);
            $this->println("Opening your browser to sign in. If it does not open, visit:\n\n  {$url}\n");
            try {
                ($this->openBrowser)($url);
            } catch (\Throwable) {
                // URL is printed; a broken browser launcher is not fatal.
            }
            $code = $server->waitForCode($state, $timeout);
        } finally {
            $server->close();
        }

        [$status, $body] = Common::postForm($this->transport, "{$baseUrl}/oauth/token", [
            'grant_type' => 'authorization_code',
            'code' => $code,
            'redirect_uri' => $redirectUri,
            'client_id' => Common::CLI_CLIENT_ID,
            'code_verifier' => $verifier,
        ]);
        if ($status >= 400 || !is_string($body['access_token'] ?? null) || $body['access_token'] === '') {
            throw new CliError(Common::tokenErrorMessage($status, $body));
        }
        return $body;
    }

    /**
     * Poll the token endpoint per RFC 8628 §3.5 — sleep BEFORE the first
     * poll, honor `slow_down` by adding 5s, stop on terminal errors.
     *
     * @return array<string, mixed> the token response body
     */
    public function pollDeviceToken(
        string $baseUrl,
        string $deviceCode,
        string $clientId = Common::CLI_CLIENT_ID,
        int $interval = 5,
        float $expiresIn = 900.0,
    ): array {
        $deadline = microtime(true) + $expiresIn;
        while (true) {
            if (microtime(true) > $deadline) {
                throw new CliError('device authorization expired — run `knoxcall login` again');
            }
            ($this->sleep)((float) $interval);
            [$status, $body] = Common::postForm($this->transport, "{$baseUrl}/oauth/token", [
                'grant_type' => self::DEVICE_GRANT,
                'device_code' => $deviceCode,
                'client_id' => $clientId,
            ]);
            if ($status < 400 && is_string($body['access_token'] ?? null) && $body['access_token'] !== '') {
                return $body;
            }
            $error = $body['error'] ?? null;
            if ($error === 'authorization_pending') {
                continue;
            }
            if ($error === 'slow_down') {
                $interval += 5;
                continue;
            }
            if ($error === 'expired_token') {
                throw new CliError('the device code expired — run `knoxcall login` again');
            }
            if ($error === 'access_denied') {
                throw new CliError('sign-in was denied');
            }
            throw new CliError(Common::tokenErrorMessage($status, $body));
        }
    }

    /** @return array<string, mixed> the token response body */
    public function deviceFlow(string $baseUrl): array
    {
        [$status, $body] = Common::postForm(
            $this->transport,
            "{$baseUrl}/oauth/device_authorization",
            ['client_id' => Common::CLI_CLIENT_ID],
        );
        $deviceCode = $body['device_code'] ?? null;
        if ($status >= 400 || !is_string($deviceCode) || $deviceCode === '') {
            throw new CliError(Common::tokenErrorMessage($status, $body));
        }

        $verificationUri = is_string($body['verification_uri'] ?? null) ? $body['verification_uri'] : '';
        $userCode = is_string($body['user_code'] ?? null) ? $body['user_code'] : '';
        $this->println("To sign in, open:\n\n  {$verificationUri}\n\nand enter the code:\n\n  {$userCode}\n");
        $complete = $body['verification_uri_complete'] ?? null;
        if (is_string($complete) && $complete !== '') {
            $this->println("(or open {$complete} directly)\n");
        }
        $this->println('Waiting for approval…');

        $interval = is_numeric($body['interval'] ?? null) ? (int) $body['interval'] : 5;
        if ($interval <= 0) {
            $interval = 5;
        }
        $expiresIn = is_numeric($body['expires_in'] ?? null) ? (float) $body['expires_in'] : 900.0;
        if ($expiresIn <= 0) {
            $expiresIn = 900.0;
        }
        return $this->pollDeviceToken($baseUrl, $deviceCode, Common::CLI_CLIENT_ID, $interval, $expiresIn);
    }

    // -- Command entry -------------------------------------------------------------

    public function run(ParsedArgs $args): int
    {
        $baseUrl = rtrim($args->baseUrl ?? Common::defaultBaseUrl($args->sandbox), '/');
        $path = Common::requireCredentialsPath();
        $profile = CredentialsFile::resolveProfile($args->profile);

        $tokenBody = ($args->device || $args->noBrowser)
            ? $this->deviceFlow($baseUrl)
            : $this->authCodeFlow($baseUrl, $args->tenant);

        $record = Common::persistLogin($path, $profile, $baseUrl, $tokenBody, $args->tenant);

        $tenant = $record['tenant'] ?? null;
        $tenant = is_string($tenant) && $tenant !== '' ? $tenant : '(tenant not reported)';
        $this->println("\nLogged in to {$tenant} ({$baseUrl})");
        $scope = $record['scope'] ?? null;
        if (is_string($scope) && $scope !== '') {
            $this->println("Scopes: {$scope}");
        }
        $this->println("Credentials written to {$path} (profile '{$profile}')");
        if (!is_string($record['refresh_token'] ?? null) || $record['refresh_token'] === '') {
            $this->println('Warning: no refresh token was issued — access will expire without renewal.');
        }
        return 0;
    }

    private function println(string $line): void
    {
        fwrite($this->out, $line . "\n");
    }
}
