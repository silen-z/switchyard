<?php

declare(strict_types=1);

namespace SilenZ\Segmatch\Tests\Http;

use PHPUnit\Framework\TestCase;
use SilenZ\Segmatch\Http\Methods;
use SilenZ\Segmatch\RouteMatch;

final class MethodsTest extends TestCase
{
    public function testOfReturnsTheRoutesMethods(): void
    {
        static::assertSame(['GET', 'HEAD'], Methods::of(['methods' => ['GET', 'HEAD']]));
    }

    public function testOfIsNullWithoutMethods(): void
    {
        static::assertNull(Methods::of(['handler' => 'any']));
        static::assertNull(Methods::of('not-a-route-array'));
    }

    public function testAcceptsIsCaseInsensitive(): void
    {
        static::assertTrue(Methods::accepts(['methods' => ['GET']], 'get'));
        static::assertFalse(Methods::accepts(['methods' => ['GET']], 'POST'));
    }

    public function testAcceptsAnyMethodWithoutMethods(): void
    {
        static::assertTrue(Methods::accepts(['handler' => 'any'], 'DELETE'));
        static::assertTrue(Methods::accepts('not-a-route-array', 'DELETE'));
    }

    public function testAllowedCollectsMethodsOfCandidatesTheGuardsStillAccept(): void
    {
        $rejected = [
            new RouteMatch(['methods' => ['GET']], []),
            new RouteMatch(['methods' => ['POST']], []),
            // No 'methods' at all: an any() route, never counted.
            new RouteMatch(['handler' => 'any'], []),
        ];

        static::assertSame(
            ['GET', 'POST'],
            Methods::allowed($rejected, static fn(): bool => true),
        );
    }

    public function testAllowedExcludesCandidatesTheGuardsReject(): void
    {
        $rejected = [
            new RouteMatch(['methods' => ['GET']], ['id' => 'john']),
            new RouteMatch(['methods' => ['PUT']], ['id' => '7']),
        ];

        static::assertSame(
            ['PUT'],
            Methods::allowed($rejected, static fn(mixed $route, array $params): bool => ($params['id'] ?? null) === '7'),
        );
    }

    public function testWithHeadAddsHeadRightAfterGet(): void
    {
        static::assertSame(['GET', 'HEAD', 'PUT'], Methods::withHead(['GET', 'PUT']));
    }

    public function testWithHeadLeavesMethodsWithoutGetAlone(): void
    {
        static::assertSame(['POST'], Methods::withHead(['POST']));
    }

    public function testWithHeadDoesNotDuplicateAnExplicitHead(): void
    {
        static::assertSame(['GET', 'HEAD'], Methods::withHead(['GET', 'HEAD']));
    }
}
