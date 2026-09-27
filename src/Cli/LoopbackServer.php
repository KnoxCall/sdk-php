<?php

declare(strict_types=1);

namespace KnoxCall\Cli;

/**
 * One-shot loopback HTTP listener on 127.0.0.1:0 for the authorize redirect
 * (RFC 8252 §7.3). Mirrors knoxcall-python cli/login.py LoopbackServer, but
 * single-threaded: construction binds the socket (so the redirect_uri port is
 * known before the browser opens), and waitForCode() then blocks in accept —
 * a connection made before that (browser redirect, or a test's
 * stream_socket_client) is held in the listen backlog.
 *
 * Non-/callback requests (favicon probes, …) get a 404 and the wait
 * continues; the /callback query string is the result. State is compared
 * constant-time (hash_equals).
 */
final class LoopbackServer
{
    private const SUCCESS_PAGE = "<!doctype html><meta charset='utf-8'><title>KnoxCall CLI</title>"
        . "<body style='font-family:system-ui;margin:4rem auto;max-width:28rem'>"
        . '<h1>Signed in</h1><p>You can close this window and return to your terminal.</p></body>';
    private const FAILURE_PAGE = "<!doctype html><meta charset='utf-8'><title>KnoxCall CLI</title>"
        . "<body style='font-family:system-ui;margin:4rem auto;max-width:28rem'>"
        . '<h1>Sign-in failed</h1><p>Return to your terminal for details.</p></body>';

    /** @var resource|null */
    private $server;

    public readonly int $port;

    public function __construct(string $host = '127.0.0.1')
    {
        $errno = 0;
        $errstr = '';
        $server = @stream_socket_server("tcp://{$host}:0", $errno, $errstr);
        if ($server === false) {
            throw new CliError("could not start the loopback sign-in listener: {$errstr}");
        }
        $this->server = $server;
        $name = (string) stream_socket_get_name($server, false); // "127.0.0.1:54321"
        $colon = strrpos($name, ':');
        $this->port = $colon === false ? 0 : (int) substr($name, $colon + 1);
        if ($this->port === 0) {
            $this->close();
            throw new CliError('could not determine the loopback listener port');
        }
    }

    /**
     * Block until the browser hits /callback; validate state, return the code.
     */
    public function waitForCode(string $expectedState, float $timeout = 300.0): string
    {
        $result = $this->waitForCallback($timeout);
        $error = $result['error'] ?? null;
        if (is_string($error) && $error !== '') {
            $detail = $result['error_description'] ?? null;
            $detail = is_string($detail) && $detail !== '' ? $detail : $error;
            throw new CliError("authorization failed: {$detail}");
        }
        if (!hash_equals($expectedState, (string) ($result['state'] ?? ''))) {
            throw new CliError('state mismatch in the OAuth callback — possible CSRF, aborting');
        }
        $code = $result['code'] ?? null;
        if (!is_string($code) || $code === '') {
            throw new CliError('no authorization code in the OAuth callback');
        }
        return $code;
    }

    public function close(): void
    {
        if ($this->server !== null) {
            @fclose($this->server);
            $this->server = null;
        }
    }

    /** @return array<string, string> the /callback query parameters */
    private function waitForCallback(float $timeout): array
    {
        if ($this->server === null) {
            throw new CliError('the loopback sign-in listener is closed');
        }
        $deadline = microtime(true) + $timeout;
        while (true) {
            $remaining = $deadline - microtime(true);
            if ($remaining <= 0) {
                throw new CliError('timed out waiting for the browser sign-in to complete');
            }
            $conn = @stream_socket_accept($this->server, $remaining);
            if ($conn === false) {
                continue; // timeout — the loop re-checks the deadline and throws
            }
            try {
                $request = $this->readRequest($conn);
                if ($request === null) {
                    continue;
                }
                [$path, $query] = $request;
                if ($path !== '/callback') {
                    $this->respond($conn, 404, self::FAILURE_PAGE);
                    continue;
                }
                parse_str($query, $params);
                $result = [];
                foreach ($params as $key => $value) {
                    if (is_string($value) && $value !== '') {
                        $result[(string) $key] = $value;
                    }
                }
                $failed = isset($result['error']) || !isset($result['code']);
                // The python reference responds 200 for both outcomes; the
                // terminal carries the details.
                $this->respond($conn, 200, $failed ? self::FAILURE_PAGE : self::SUCCESS_PAGE);
                return $result;
            } finally {
                @fclose($conn);
            }
        }
    }

    /**
     * Read the request line (+ drain headers, bounded by a 5s stream timeout).
     *
     * @param resource $conn
     * @return array{0: string, 1: string}|null [path, query] — null on garbage
     */
    private function readRequest($conn): ?array
    {
        stream_set_timeout($conn, 5);
        $requestLine = fgets($conn, 8192);
        if ($requestLine === false) {
            return null;
        }
        while (($line = fgets($conn, 8192)) !== false) {
            if ($line === "\r\n" || $line === "\n") {
                break;
            }
        }
        $parts = explode(' ', trim($requestLine));
        if (count($parts) < 2) {
            return null;
        }
        $path = parse_url($parts[1], PHP_URL_PATH);
        $query = parse_url($parts[1], PHP_URL_QUERY);
        return [is_string($path) ? $path : '', is_string($query) ? $query : ''];
    }

    /** @param resource $conn */
    private function respond($conn, int $status, string $html): void
    {
        $reason = $status === 404 ? 'Not Found' : 'OK';
        @fwrite(
            $conn,
            "HTTP/1.1 {$status} {$reason}\r\n"
            . "Content-Type: text/html; charset=utf-8\r\n"
            . 'Content-Length: ' . strlen($html) . "\r\n"
            . "Connection: close\r\n\r\n"
            . $html,
        );
    }
}
