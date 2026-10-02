<?php

declare(strict_types=1);

namespace SilenZ\Segmatch\Tests\Http;

use PHPUnit\Framework\TestCase;
use SilenZ\Segmatch\Http\Methods;

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
}
