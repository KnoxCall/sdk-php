<?php

declare(strict_types=1);

namespace KnoxCall\Cli;

use KnoxCall\Auth\CredentialsFile;
use KnoxCall\Auth\CredentialsFileLock;
use KnoxCall\ConnectionException;
use KnoxCall\Transport\TransportInterface;

/**
 * Shared CLI plumbing — token-endpoint POSTs, profile persistence. Mirrors
 * knoxcall-python cli/_common.py; all HTTP goes through the SDK's
 * TransportInterface so tests inject the house MockTransport.
 */
final class Common
{
    /**
     * Reserved alias accepted by /oauth/authorize and the device endpoints;
     * the server lazily provisions the tenant's real CLI client and returns
     * its id as the `client_id` extension member on the token response.
     */
    public const CLI_CLIENT_ID = 'knoxcall-cli';

    private function __construct()
    {
    }

    /** `--base-url` beats this; env beats the sandbox/production default. */
    public static function defaultBaseUrl(bool $sandbox): string
    {
        $env = getenv('KNOXCALL_BASE_URL');
        if (is_string($env) && $env !== '') {
            return $env;
        }
        return $sandbox ? 'https://sandbox.knoxcall.com' : 'https://api.knoxcall.com';
    }

    /** Credentials-file path, or a human CliError when no home dir resolves. */
    public static function requireCredentialsPath(): string
    {
        $path = CredentialsFile::resolvePath();
        if ($path === null) {
            throw new CliError(
                'could not resolve the credentials file path — set KNOXCALL_CREDENTIALS_FILE or HOME'
            );
        }
        return $path;
    }

    /**
     * POST a urlencoded form; return [status, parsed-JSON-or-empty-array].
     *
     * Transport failures raise CliError with a human message. HTTP error
     * statuses are returned, not raised — device polling needs the error
     * codes.
     *
     * @param array<string, string> $form
     * @return array{0: int, 1: array<string, mixed>}
     */
    public static function postForm(TransportInterface $transport, string $url, array $form): array
    {
        try {
            $response = $transport->send('POST', $url, [
                'Content-Type' => 'application/x-www-form-urlencoded',
                'Accept' => 'application/json',
            ], http_build_query($form), 30000);
        } catch (ConnectionException $e) {
            throw new CliError("could not reach {$url}: {$e->getMessage()}", 0, $e);
        }
        $body = json_decode($response['body'], true);
        return [$response['status'], is_array($body) ? $body : []];
    }

    /** @param array<string, mixed> $body */
    public static function tokenErrorMessage(int $status, array $body): string
    {
        $detail = $body['error_description'] ?? null;
        if (!is_string($detail) || $detail === '') {
            $detail = $body['error'] ?? null;
        }
        if (!is_string($detail) || $detail === '') {
            $detail = "HTTP {$status}";
        }
        return "sign-in failed: {$detail}";
    }

    /**
     * Store a successful token response as a credentials-file profile,
     * UNDER THE FILE LOCK — a login racing a concurrent SDK refresh must
     * not lose a rotation.
     *
     * Persists the `tenant` and `client_id` extension members — refreshes
     * must use the REAL per-tenant client id, not the `knoxcall-cli` alias.
     *
     * @param array<string, mixed> $tokenBody
     * @return array<string, mixed> the record as written
     */
    public static function persistLogin(
        string $path,
        string $profile,
        string $baseUrl,
        array $tokenBody,
        ?string $fallbackTenant = null,
    ): array {
        $expiresIn = is_numeric($tokenBody['expires_in'] ?? null) ? (float) $tokenBody['expires_in'] : 3600.0;
        $tenant = $tokenBody['tenant'] ?? null;
        $clientId = $tokenBody['client_id'] ?? null;
        $record = [
            'tenant' => is_string($tenant) && $tenant !== '' ? $tenant : $fallbackTenant,
            'base_url' => $baseUrl,
            'client_id' => is_string($clientId) && $clientId !== '' ? $clientId : self::CLI_CLIENT_ID,
            'refresh_token' => $tokenBody['refresh_token'] ?? null,
            'access_token' => $tokenBody['access_token'] ?? null,
            'access_token_expires_at' => CredentialsFile::formatExpiry(microtime(true) + $expiresIn),
            'scope' => $tokenBody['scope'] ?? '',
        ];
        $lock = new CredentialsFileLock($path);
        $lock->acquire();
        try {
            CredentialsFile::writeProfile($path, $profile, $record);
        } finally {
            $lock->release();
        }
        return $record;
    }
}
