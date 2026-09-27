<?php

declare(strict_types=1);

namespace KnoxCall\Tests;

use KnoxCall\ConnectionException;
use KnoxCall\NotFoundException;
use KnoxCall\PermissionDeniedException;
use KnoxCall\Warn;
use KnoxCall\Wrap\InterceptManifestStore;
use PHPUnit\Framework\TestCase;

/**
 * The SDK-side manifest store (route-aware-interception-plan.md §2.5), PHP
 * idiom: LAZY refresh at the TTL, stale-keep with backoff, permission refusals
 * as "no manifest" with one warning, rate-limited hints, and an optional
 * PSR-16 cache shared across processes. The clock is injected so every
 * schedule is asserted as a value.
 */
final class InterceptManifestStoreTest extends TestCase
{
    private float $clock = 1000.0;

    protected function setUp(): void
    {
        Warn::resetForTests();
        $this->clock = 1000.0;
    }

    protected function tearDown(): void
    {
        Warn::resetForTests();
    }

    /** @param array<string, mixed> $opts */
    private function store(\Closure $fetch, array $opts = []): InterceptManifestStore
    {
        return new InterceptManifestStore(
            $fetch,
            $opts['on_refresh'] ?? null,
            $opts['on_error'] ?? null,
            $opts['gap'] ?? 5.0,
            fn (): float => $this->clock,
            $opts['cache'] ?? null,
            $opts['cache_key'] ?? '',
        );
    }

    /** @return array<string, mixed> */
    private static function entry(string $host, string $slug, string $base = '/'): array
    {
        return ['host' => $host, 'base_path' => $base, 'slug' => $slug, 'route_id' => "id-{$slug}",
            'requires_clients' => false, 'allowed_methods' => null, 'updated_at' => null];
    }

    /**
     * @param list<array<string, mixed>> $routes
     * @return array<string, mixed>
     */
    private static function manifest(array $routes, ?string $version = null): array
    {
        return ['version' => $version ?? 'v:' . implode(',', array_column($routes, 'slug')), 'ttl_seconds' => 60,
            'environment' => 'production', 'sandbox' => false, 'routes' => $routes];
    }

    /** @return list<string> the E_USER_WARNING messages $fn emitted */
    private function captureWarnings(callable $fn): array
    {
        $messages = [];
        set_error_handler(static function (int $errno, string $errstr) use (&$messages): bool {
            $messages[] = $errstr;
            return true;
        }, E_USER_WARNING);
        try {
            $fn();
        } finally {
            restore_error_handler();
        }
        return $messages;
    }

    public function testFirstEnsureFetchesOnceAndReportsEveryEntryAsAdded(): void
    {
        $calls = 0;
        $refreshes = [];
        $store = $this->store(function () use (&$calls): array {
            $calls++;
            return self::manifest([self::entry('a.example', 'a')]);
        }, ['on_refresh' => static function (array $info) use (&$refreshes): void { $refreshes[] = $info; }]);

        self::assertTrue($store->stale());
        $store->ensure();
        $store->ensure(); // fresh: no second call
        self::assertSame(1, $calls);
        self::assertSame(['a'], array_column($store->manifest()['routes'], 'slug'));
        self::assertSame('v:a', $store->version());
        self::assertSame('ttl', $refreshes[0]['reason']);
        self::assertSame([self::entry('a.example', 'a')], $refreshes[0]['added']);
        self::assertSame([], $refreshes[0]['removed']);
    }

    public function testStaleAfterTtlAndOnlyTheDiffIsReported(): void
    {
        $routes = [self::entry('a.example', 'a')];
        $refreshes = [];
        $store = $this->store(function () use (&$routes): array {
            return self::manifest($routes);
        }, ['on_refresh' => static function (array $info) use (&$refreshes): void { $refreshes[] = $info; }]);
        $store->ensure();
        $this->clock += 59;
        self::assertFalse($store->stale());
        $routes = [self::entry('b.example', 'b')];
        $this->clock += 2;
        self::assertTrue($store->stale());
        $store->ensure();
        self::assertSame(['b'], array_column($store->manifest()['routes'], 'slug'));
        $last = $refreshes[count($refreshes) - 1];
        self::assertSame([self::entry('b.example', 'b')], $last['added']);
        self::assertSame([self::entry('a.example', 'a')], $last['removed']);
    }

    public function testUnchangedVersionFiresNoRefreshHook(): void
    {
        $hooks = 0;
        $store = $this->store(static fn (): array => self::manifest([self::entry('a.example', 'a')]),
            ['on_refresh' => static function () use (&$hooks): void { $hooks++; }]);
        $store->ensure();
        $this->clock += 61;
        $store->ensure();
        self::assertSame(1, $hooks);
    }

    public function testTransportFaultKeepsLastGoodManifestAndBacksOffFromSecondFailure(): void
    {
        $fail = false;
        $calls = 0;
        $errors = [];
        $store = $this->store(function () use (&$fail, &$calls): array {
            $calls++;
            if ($fail) {
                throw new ConnectionException('connection reset', false);
            }
            return self::manifest([self::entry('a.example', 'a')]);
        }, ['on_error' => static function (\Throwable $e) use (&$errors): void { $errors[] = $e; }]);
        $store->ensure();
        $fail = true;
        $this->clock += 61;
        $store->ensure(); // 2nd call, fails
        self::assertSame(2, $calls);
        self::assertCount(1, $store->manifest()['routes']); // stale-but-valid
        self::assertInstanceOf(ConnectionException::class, $store->lastError());
        self::assertCount(1, $errors);
        // one transient failure keeps the TTL…
        $this->clock += 61;
        $store->ensure();
        self::assertSame(3, $calls);
        // …the second consecutive failure doubles it: nothing at +60, a call at +120
        $this->clock += 61;
        $store->ensure();
        self::assertSame(3, $calls);
        $this->clock += 61;
        $store->ensure();
        self::assertSame(4, $calls);
    }

    public function testPermissionRefusalIsNoManifestWarnsOnceAndRechecksSlowly(): void
    {
        $denied = true;
        $calls = 0;
        $store = $this->store(function () use (&$denied, &$calls): array {
            $calls++;
            if ($denied) {
                throw new PermissionDeniedException('insufficient scope', 403);
            }
            return self::manifest([self::entry('a.example', 'a')]);
        });

        $warnings = $this->captureWarnings(static fn () => $store->ensure());
        self::assertCount(1, $warnings);
        self::assertStringContainsString('routes:read', $warnings[0]);
        self::assertNull($store->manifest());
        self::assertTrue($store->permissionDenied());
        $this->clock += 5 * 60;
        $again = $this->captureWarnings(static fn () => $store->ensure()); // not yet re-checked
        self::assertSame([], $again);
        self::assertSame(1, $calls);
        $denied = false;
        $this->clock += 301;
        $store->ensure();
        self::assertSame(2, $calls);
        self::assertFalse($store->permissionDenied());
        self::assertCount(1, $store->manifest()['routes']);
    }

    public function testNotFoundFromAnOlderServerIsAlsoNoManifest(): void
    {
        $store = $this->store(static function (): array {
            throw new NotFoundException('no such route', 404);
        });
        $warnings = $this->captureWarnings(static fn () => $store->ensure());
        self::assertStringContainsString('HTTP 404', $warnings[0] ?? '');
        self::assertTrue($store->permissionDenied());
    }

    public function testOutOfCycleRefreshIsRateLimitedAndForceBypassesTheGap(): void
    {
        $calls = 0;
        $store = $this->store(function () use (&$calls): array {
            $calls++;
            return self::manifest([]);
        }, ['gap' => 5.0]);
        $store->refresh('start', true);
        $store->refresh('hint'); // inside the gap → no call
        self::assertSame(1, $calls);
        $this->clock += 6;
        $store->refresh('hint');
        self::assertSame(2, $calls);
        $store->refresh('manual', true);
        self::assertSame(3, $calls);
    }

    public function testHintMakesTheNextEnsureRefreshRateLimited(): void
    {
        $calls = 0;
        $store = $this->store(function () use (&$calls): array {
            $calls++;
            return self::manifest([]);
        });
        $store->ensure();
        $store->hint(); // inside the 5 s gap of the refresh just done: ignored
        self::assertFalse($store->stale());
        $this->clock += 6;
        $store->hint();
        self::assertTrue($store->stale());
        $store->ensure();
        self::assertSame(2, $calls);
    }

    public function testStopDropsTheManifestAndRefusesToRefresh(): void
    {
        $store = $this->store(static fn (): array => self::manifest([self::entry('a.example', 'a')]));
        $store->ensure();
        $store->stop();
        self::assertNull($store->manifest());
        self::assertFalse($store->stale());
        self::assertNull($store->refresh('x', true));
    }

    public function testFirstFailureNeverThrowsOutOfEnsure(): void
    {
        $store = $this->store(static function (): array {
            throw new \RuntimeException('boom');
        });
        self::assertNull($store->ensure());
        self::assertInstanceOf(\RuntimeException::class, $store->lastError());
    }

    public function testASharedCacheLetsAnotherProcessSkipTheFetchInsideTheTtl(): void
    {
        $cache = new FakeSimpleCache();
        $calls = 0;
        $fetch = function () use (&$calls): array {
            $calls++;
            return self::manifest([self::entry('a.example', 'a')]);
        };
        $first = $this->store($fetch, ['cache' => $cache, 'cache_key' => 'k']);
        $first->ensure();
        self::assertSame(1, $calls);
        self::assertSame(60, $cache->ttls['k'] ?? null);

        // "Another process": a fresh store on the same key adopts the cached copy.
        $second = $this->store($fetch, ['cache' => $cache, 'cache_key' => 'k']);
        self::assertSame(['a'], array_column($second->ensure()['routes'], 'slug'));
        self::assertSame(1, $calls);
        self::assertFalse($second->stale());

        // An expired record is ignored and the fetch happens.
        $cache->values['k']['fetched_at'] = microtime(true) - 61;
        $third = $this->store($fetch, ['cache' => $cache, 'cache_key' => 'k']);
        $third->ensure();
        self::assertSame(2, $calls);
    }

    // ── the conditional poll (PARITY §21.1 "Conditional poll") ──────────────────
    // Driven by the CROSS-LANGUAGE fixture sdk/fixtures/intercept-store-conditional.json;
    // node (sdk/knoxcall-node/test/intercept-manifest-store.test.ts) is the reference.

    /** @return array{manifests: array<string, array<string, mixed>>, steps: list<array<string, mixed>>} */
    private static function conditionalFixture(): array
    {
        $raw = file_get_contents(__DIR__ . '/../../fixtures/intercept-store-conditional.json');
        self::assertIsString($raw);
        $fixture = json_decode($raw, true);
        self::assertIsArray($fixture);
        self::assertGreaterThanOrEqual(4, count($fixture['steps']));
        self::assertNull($fixture['steps'][0]['expect']['fetch_if_none_match']);
        self::assertNotEmpty(array_filter($fixture['steps'], static fn (array $s): bool => $s['respond']['status'] === 304));
        self::assertNotEmpty(array_filter($fixture['steps'], static fn (array $s): bool => (bool) ($s['forced'] ?? false)));
        return $fixture;
    }

    public function testConditionalPollWalksEveryFixtureStep(): void
    {
        $fixture = self::conditionalFixture();
        $sent = [];
        $respond = [];
        $refreshes = [];
        $store = $this->store(
            function (?string $ifNoneMatch = null) use (&$sent, &$respond, $fixture): ?array {
                $sent[] = $ifNoneMatch;
                if ($respond['status'] === 304) {
                    return null;
                }
                return $fixture['manifests'][$respond['manifest']];
            },
            ['on_refresh' => function (array $info) use (&$refreshes): void {
                $refreshes[] = $info;
            }],
        );

        foreach ($fixture['steps'] as $i => $step) {
            $respond = $step['respond'];
            $refreshes = [];
            if ($i === 0) {
                $store->ensure();
            } elseif ($step['forced'] ?? false) {
                $store->refresh('route_refused', true);
            } else {
                $this->clock += 61; // one TTL after the previous answer
                self::assertTrue($store->stale(), $step['name']);
                $store->ensure();
            }
            self::assertCount($i + 1, $sent, $step['name']);
            self::assertSame($step['expect']['fetch_if_none_match'], $sent[$i], $step['name']);
            self::assertSame($step['expect']['version'], $store->version(), $step['name']);
            self::assertSame($step['expect']['version'], $store->manifest()['version'] ?? null, $step['name']);
            self::assertNull($store->lastError(), $step['name']);
            self::assertFalse($store->stale(), $step['name']); // every answer, a 304 included, restarts the clock
            if ($step['expect']['refresh_fired']) {
                self::assertCount(1, $refreshes, $step['name']);
                self::assertSame($step['expect']['version'], $refreshes[0]['version'], $step['name']);
                self::assertSame($step['expect']['added'], array_column($refreshes[0]['added'], 'slug'), $step['name']);
                self::assertSame($step['expect']['removed'], array_column($refreshes[0]['removed'], 'slug'), $step['name']);
            } else {
                self::assertSame([], $refreshes, $step['name']);
            }
        }
    }

    public function testA304ClearsTheBackoffARunOfFaultsBuiltUp(): void
    {
        $mode = 'ok';
        $store = $this->store(function (?string $ifNoneMatch = null) use (&$mode): ?array {
            if ($mode === 'fault') {
                throw new ConnectionException('connection reset', false);
            }
            if ($mode === 'not_modified') {
                return null;
            }
            return self::manifest([self::entry('a.example', 'a')]);
        });
        $store->ensure();
        $mode = 'fault';
        $this->clock += 61;
        $store->ensure(); // fault #1 → next at TTL
        $this->clock += 61;
        $store->ensure(); // fault #2 → next at 2×TTL
        $this->clock += 61;
        self::assertFalse($store->stale());
        $this->clock += 60;
        $mode = 'not_modified';
        $store->ensure(); // a 304 after the 2×TTL wait
        self::assertSame(['a'], array_column($store->manifest()['routes'] ?? [], 'slug'));
        self::assertNull($store->lastError());
        $this->clock += 61; // back to one TTL, not 4×
        self::assertTrue($store->stale());
    }

    public function testAfterAPermissionRefusalTheRecoveryPollIsUnconditionalAgain(): void
    {
        $denied = false;
        $sent = [];
        $store = $this->store(function (?string $ifNoneMatch = null) use (&$denied, &$sent): ?array {
            $sent[] = $ifNoneMatch;
            if ($denied) {
                throw new PermissionDeniedException('insufficient scope', 403);
            }
            return self::manifest([self::entry('a.example', 'a')]);
        });
        $store->ensure();
        $denied = true;
        $this->captureWarnings(static fn () => $store->refresh('manual', true));
        self::assertSame([null, 'v:a'], $sent);
        self::assertNull($store->version());
        $denied = false;
        $store->refresh('manual', true);
        self::assertNull($sent[2]);
        self::assertSame('v:a', $store->version());
    }

    public function testAZeroParameterFetchKeepsPollingUnconditionally(): void
    {
        $calls = 0;
        $store = $this->store(function () use (&$calls): array {
            $calls++;
            return self::manifest([self::entry('a.example', 'a')]);
        });
        $store->ensure();
        $this->clock += 61;
        $store->ensure();
        self::assertSame(2, $calls);
        self::assertSame('v:a', $store->version());
    }

    public function testA304RewritesTheSharedCacheRecordSoTheNextProcessKeepsSkippingTheFetch(): void
    {
        $cache = new FakeSimpleCache();
        $answer = self::manifest([self::entry('a.example', 'a')]);
        $store = $this->store(
            static fn (?string $ifNoneMatch = null): ?array => $ifNoneMatch === $answer['version'] ? null : $answer,
            ['cache' => $cache, 'cache_key' => 'k'],
        );
        $store->ensure();
        self::assertSame('v:a', $cache->values['k']['manifest']['version'] ?? null);
        unset($cache->values['k']);
        $this->clock += 61;
        $store->ensure(); // the 304
        self::assertSame('v:a', $cache->values['k']['manifest']['version'] ?? null, 'a 304 re-stamps the shared record');
        self::assertSame(60, $cache->ttls['k'] ?? null);
    }

    public function testRejectsACacheThatIsNotPsr16Shaped(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new InterceptManifestStore(static fn (): array => [], null, null, 5.0, null, new \stdClass(), 'k');
    }
}

/** A PSR-16-shaped cache double: get/set with recorded TTLs. */
final class FakeSimpleCache
{
    /** @var array<string, mixed> */
    public array $values = [];

    /** @var array<string, int|null> */
    public array $ttls = [];

    public function get(string $key, mixed $default = null): mixed
    {
        return $this->values[$key] ?? $default;
    }

    public function set(string $key, mixed $value, mixed $ttl = null): bool
    {
        $this->values[$key] = $value;
        $this->ttls[$key] = is_int($ttl) ? $ttl : null;
        return true;
    }
}
