<?php

declare(strict_types=1);

namespace KnoxCall\Tests;

use KnoxCall\Wrap\InterceptResolver;
use PHPUnit\Framework\TestCase;

/**
 * The KNOXCALL_INTERCEPT off-grammar, driven by the CROSS-LANGUAGE fixture
 * sdk/fixtures/intercept-kill-switch.json (PARITY §21.1).
 *
 * Five SDKs hand-write this predicate and already agree character for
 * character. A sixth spelling accepted here would be a kill switch that works
 * in four SDKs and silently does nothing in the fifth — the worst shape a kill
 * switch can have, because the operator believes interception is off.
 *
 * A missing fixture FAILS rather than skips: a skipped contract test reads as
 * a pass.
 */
final class InterceptKillSwitchTest extends TestCase
{
    /** @var string|false */
    private $saved = false;

    protected function setUp(): void
    {
        $this->saved = getenv('KNOXCALL_INTERCEPT');
    }

    protected function tearDown(): void
    {
        if ($this->saved === false) {
            putenv('KNOXCALL_INTERCEPT');
        } else {
            putenv('KNOXCALL_INTERCEPT=' . $this->saved);
        }
    }

    /** @return array<string, array{0: ?string, 1: bool}> */
    public static function grammarProvider(): array
    {
        $path = __DIR__ . '/../../fixtures/intercept-kill-switch.json';
        $raw = file_get_contents($path);
        if ($raw === false) {
            throw new \RuntimeException("the shared kill-switch fixture must exist at {$path}");
        }
        /** @var array{cases: list<array{name: string, value: ?string, expect: bool}>} $fixture */
        $fixture = json_decode((string) $raw, true, 512, JSON_THROW_ON_ERROR);

        $out = [];
        foreach ($fixture['cases'] as $case) {
            $out[$case['name']] = [$case['value'], $case['expect']];
        }
        return $out;
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('grammarProvider')]
    public function testKillSwitchGrammar(?string $value, bool $expect): void
    {
        if ($value === null) {
            putenv('KNOXCALL_INTERCEPT');
        } else {
            putenv('KNOXCALL_INTERCEPT=' . $value);
        }
        self::assertSame($expect, InterceptResolver::killSwitch());
    }

    public function testTheFixtureIsNotVacuous(): void
    {
        $outcomes = [];
        foreach (self::grammarProvider() as [$_value, $expect]) {
            $outcomes[$expect ? 'true' : 'false'] = true;
        }
        self::assertCount(2, $outcomes, 'the table must contain both verdicts');
    }
}
