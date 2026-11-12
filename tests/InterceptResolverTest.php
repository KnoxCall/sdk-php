<?php

declare(strict_types=1);

namespace KnoxCall\Tests;

use KnoxCall\Wrap\InterceptResolver;
use KnoxCall\Wrap\WrapTransport;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The route-aware decision table, driven by the CROSS-LANGUAGE fixtures in
 * sdk/fixtures/intercept-resolver.json (route-aware-interception-plan.md §2.2,
 * PARITY §21.1). Node is the reference; this is the PHP mirror running the
 * same cases unchanged. A missing fixture FAILS rather than skips — a skipped
 * contract test reads as a pass.
 */
final class InterceptResolverTest extends TestCase
{
    /** @return array<string, mixed> */
    private static function fixture(): array
    {
        $path = __DIR__ . '/../../fixtures/intercept-resolver.json';
        if (!is_file($path)) {
            throw new \RuntimeException("the shared resolver fixture must exist at {$path} — every SDK's resolver runs it");
        }
        return json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
    }

    /** @return list<string> */
    private static function hosts(array $hosts): array
    {
        return array_map(static fn ($h): string => WrapTransport::normalizeHost((string) $h), $hosts);
    }

    /** @return iterable<string, array{array<string, mixed>}> */
    public static function cases(): iterable
    {
        foreach (self::fixture()['cases'] as $case) {
            yield $case['name'] => [$case];
        }
    }

    public function testFixtureIsNonTrivial(): void
    {
        $f = self::fixture();
        self::assertGreaterThan(15, count($f['cases']));
        self::assertGreaterThan(3, count($f['manifest']['routes']));
    }

    /** @param array<string, mixed> $case */
    #[DataProvider('cases')]
    public function testSharedFixtureCase(array $case): void
    {
        $f = self::fixture();
        $manifest = array_key_exists('manifest', $case) ? $case['manifest'] : $f['manifest'];
        $d = InterceptResolver::resolve([
            'url' => $case['url'],
            'method' => $case['method'],
            'hosts' => $case['hosts'] === 'all' ? 'all' : self::hosts($case['hosts']),
            'manifest' => $manifest,
            'own_hosts' => self::hosts($f['own_hosts']),
            'route_around' => WrapTransport::defaultRouteAround(),
            'kill_switch' => $case['kill_switch'],
            'require_context' => $case['require_context'],
            'in_context' => $case['in_context'],
        ]);
        $exp = $case['expect'];
        self::assertSame($exp['mode'], $d['mode'], "{$case['url']}: mode (reason {$d['reason']})");
        self::assertSame($exp['reason'], $d['reason'], "{$case['url']}: reason");
        if (array_key_exists('slug', $exp)) {
            self::assertSame($exp['slug'], $d['slug'] ?? null);
        }
        if (array_key_exists('path', $exp)) {
            self::assertSame($exp['path'], $d['path'] ?? null);
        }
        if ($d['mode'] === InterceptResolver::MODE_ROUTE) {
            self::assertSame($d['slug'], $d['entry']['slug']);
        } else {
            self::assertArrayNotHasKey('slug', $d);
            self::assertArrayNotHasKey('path', $d);
            self::assertArrayNotHasKey('entry', $d);
        }
    }

    public function testRebasePathIsSegmentAware(): void
    {
        self::assertSame('/objects', InterceptResolver::rebasePath('/crm/v3/objects', '/crm/v3'));
        self::assertSame('/', InterceptResolver::rebasePath('/crm/v3', '/crm/v3'));
        self::assertNull(InterceptResolver::rebasePath('/crm/v30/x', '/crm/v3'));
        self::assertSame('/anything', InterceptResolver::rebasePath('/anything', '/'));
        self::assertSame('/', InterceptResolver::rebasePath('', '/'));
        self::assertSame('/x', InterceptResolver::rebasePath('x', '/'));
        self::assertNull(InterceptResolver::rebasePath('/other', '/crm/v3'));
    }

    public function testNormalizeHostContract(): void
    {
        self::assertSame('api.example', WrapTransport::normalizeHost(' API.Example. '));
        self::assertSame('::1', WrapTransport::normalizeHost('[::1]'));
        self::assertSame('', WrapTransport::normalizeHost(''));
    }

    public function testIsPlatformHost(): void
    {
        self::assertTrue(InterceptResolver::isPlatformHost('knoxcall.com'));
        self::assertTrue(InterceptResolver::isPlatformHost('acme.knoxcall.com'));
        self::assertTrue(InterceptResolver::isPlatformHost('x.wrap.knoxcall.com'));
        self::assertFalse(InterceptResolver::isPlatformHost('knoxcall.com.evil.example'));
        self::assertFalse(InterceptResolver::isPlatformHost('notknoxcall.com'));
    }

    public function testEntriesForHostOrdersLongestBaseThenSlug(): void
    {
        $m = ['routes' => [
            ['host' => 'h.example', 'base_path' => '/', 'slug' => 'z'],
            ['host' => 'h.example', 'base_path' => '/a/b', 'slug' => 'deep'],
            ['host' => 'H.EXAMPLE.', 'base_path' => '/', 'slug' => 'a'],
            ['host' => 'other.example', 'base_path' => '/', 'slug' => 'o'],
        ]];
        self::assertSame(['deep', 'a', 'z'], array_column(InterceptResolver::entriesForHost($m, 'h.example'), 'slug'));
        self::assertSame([], InterceptResolver::entriesForHost(null, 'h.example'));
    }

    public function testPortInRequestUrlNeverAffectsHostMatch(): void
    {
        $m = ['routes' => [['host' => 'h.example', 'base_path' => '/', 'slug' => 'h']]];
        $d = InterceptResolver::resolve(['url' => 'https://h.example:8443/x?y=1', 'method' => 'GET', 'hosts' => [],
            'manifest' => $m, 'own_hosts' => [], 'route_around' => []]);
        self::assertSame(['route', 'h', '/x?y=1'], [$d['mode'], $d['slug'], $d['path']]);
    }

    public function testKillSwitchSpellings(): void
    {
        $previous = getenv('KNOXCALL_INTERCEPT');
        try {
            foreach (['off' => true, 'OFF' => true, ' 0 ' => true, 'false' => true, 'on' => false, '' => false, '1' => false] as $v => $want) {
                putenv("KNOXCALL_INTERCEPT={$v}");
                self::assertSame($want, InterceptResolver::killSwitch(), "KNOXCALL_INTERCEPT=" . json_encode($v));
            }
        } finally {
            putenv($previous === false ? 'KNOXCALL_INTERCEPT' : "KNOXCALL_INTERCEPT={$previous}");
        }
    }
}
