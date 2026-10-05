<?php

declare(strict_types=1);

namespace KnoxCall\Tests;

use KnoxCall\Wrap\RouteRefusal;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The route-mode refusal predicate, driven by the CROSS-LANGUAGE fixtures in
 * sdk/fixtures/route-refusal.json (PARITY §21.1, "Refusal-driven refresh").
 * Node is the reference; this file consumes the same cases unchanged.
 */
final class RouteRefusalTest extends TestCase
{
    /** @return array<string, array{0: array<string, mixed>}> */
    public static function cases(): array
    {
        $path = __DIR__ . '/../../fixtures/route-refusal.json';
        $fixture = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
        $out = [];
        foreach ($fixture['cases'] as $case) {
            $out[$case['name']] = [$case];
        }
        return $out;
    }

    public function testFixtureAnswersInBothDirections(): void
    {
        $answers = [];
        foreach (self::cases() as [$case]) {
            $answers[$case['expect']['refusal'] ? 'true' : 'false'] = true;
        }
        self::assertSame(['true' => true, 'false' => true], $answers);
        self::assertGreaterThanOrEqual(10, count(self::cases()));
    }

    /** @param array<string, mixed> $case */
    #[DataProvider('cases')]
    public function testRouteRefusalPredicate(array $case): void
    {
        self::assertSame(
            $case['expect']['refusal'],
            RouteRefusal::isRefusal($case['status'], $case['headers'], $case['body']),
            $case['name'],
        );
    }

    public function testHeaderLookupIsCaseInsensitiveAndArrayTolerant(): void
    {
        $envelope = '{"error":{"type":"route_not_found","message":"x","request_id":"r"}}';
        self::assertFalse(RouteRefusal::isRefusal(404, ['x-knox-upstream-status' => '404'], $envelope));
        self::assertFalse(RouteRefusal::isRefusal(404, ['X-KNOX-DESTINATION-STATUS' => ['404']], $envelope));
        self::assertTrue(RouteRefusal::isRefusal(404, ['x-knox-upstream-status' => ''], $envelope), 'an empty stamp is no stamp');
    }
}
