<?php

declare(strict_types=1);

namespace KnoxCall\Cli;

use KnoxCall\KnoxCallException;
use KnoxCall\Transport\CurlTransport;
use KnoxCall\Transport\TransportInterface;

/**
 * `knoxcall` CLI dispatcher — parse, run, and enforce the PARITY §13 error
 * contract: expected failures print `error: <message>` to stderr and exit 1
 * (no stack traces); help exits 0; usage errors exit 2 (argparse parity);
 * secrets never appear in output.
 *
 * All collaborators are injectable so the commands are unit-testable without
 * a process, real sockets to the network, or real sleeps:
 *   transport    TransportInterface for token/device/revoke HTTP (whoami
 *                passes it into the SDK client) — default CurlTransport
 *   sleep        fn (float $seconds): void — default usleep
 *   openBrowser  fn (string $url): void — default per-OS start/open/xdg-open
 *   out / err    stream resources — default STDOUT / STDERR
 */
final class Cli
{
    private readonly ?TransportInterface $transport;
    private readonly \Closure $sleep;
    private readonly \Closure $openBrowser;

    /** @var resource */
    private $out;
    /** @var resource */
    private $err;

    public function __construct(
        ?TransportInterface $transport = null,
        ?\Closure $sleep = null,
        ?\Closure $openBrowser = null,
        $out = null,
        $err = null,
    ) {
        $this->transport = $transport;
        $this->sleep = $sleep ?? static function (float $seconds): void {
            if ($seconds > 0) {
                usleep((int) round($seconds * 1_000_000));
            }
        };
        $this->openBrowser = $openBrowser ?? static function (string $url): void {
            Login::openSystemBrowser($url);
        };
        $this->out = $out ?? (\defined('STDOUT') ? \STDOUT : fopen('php://output', 'w'));
        $this->err = $err ?? (\defined('STDERR') ? \STDERR : fopen('php://output', 'w'));
    }

    /**
     * Run the CLI for the given arguments (WITHOUT the program name) and
     * return the process exit code.
     *
     * @param list<string> $argv
     */
    public function run(array $argv): int
    {
        try {
            $args = ArgParser::parse($argv);
        } catch (UsageError $e) {
            fwrite($this->err, $e->usage . "\n");
            fwrite($this->err, 'knoxcall: error: ' . $e->getMessage() . "\n");
            return 2;
        }
        if ($args->help) {
            // `ai` help is per SUB-command: `ai --help` lists the group,
            // `ai mint --help` documents mint's own flags. A single `ai` help
            // page would describe one sub-command's flags and silently omit
            // the other five, which is where users discover them.
            fwrite($this->out, ArgParser::help($this->helpKey($args)));
            return 0;
        }

        try {
            return match ($args->command) {
                'login' => (new Login($this->transport ?? new CurlTransport(), $this->sleep, $this->openBrowser, $this->out))->run($args),
                'logout' => (new Logout($this->transport ?? new CurlTransport(), $this->out))->run($args),
                'whoami' => (new Whoami($this->transport, $this->out))->run($args),
                'init' => (new Init($this->transport, $this->out))->run($args),
                // Two auth models under one command group (AIGW-162):
                // `exchange` is the data plane and needs no login — the CI
                // workload's OIDC token IS the credential — while the other
                // five act as the signed-in tenant through the credentials
                // file. Keeping them in separate classes is what stops one
                // acquiring the other's credential handling by accident.
                'ai' => $args->aiCommand === 'exchange'
                    ? (new Ai($this->transport, $this->out, $this->err))->run($args)
                    : (new AiControl($this->transport, $this->out, $this->err))->run($args),
                default => throw new \LogicException('unreachable: ArgParser vets the command'),
            };
        } catch (CliError | KnoxCallException $e) {
            fwrite($this->err, 'error: ' . $e->getMessage() . "\n");
            return 1;
        }
    }

    /** `ai mint` for `ai mint --help`, plain `ai` for `ai --help`. */
    private function helpKey(ParsedArgs $args): ?string
    {
        if ($args->command === 'ai' && $args->aiCommand !== null) {
            return 'ai ' . $args->aiCommand;
        }
        return $args->command;
    }
}
