<?php

declare(strict_types=1);

namespace SilenZ\Switchyard\Tests\Attribute;

use PHPUnit\Framework\TestCase;
use SilenZ\Switchyard\Attribute\MethodNotAllowed;

final class MethodNotAllowedTest extends TestCase
{
    public function testOfReturnsTheRoutesMethods(): void
    {
        static::assertSame(['GET', 'HEAD'], MethodNotAllowed::of(['methods' => ['GET', 'HEAD']]));
    }

    public function testOfIsNullWithoutMethods(): void
    {
        static::assertNull(MethodNotAllowed::of(['handler' => 'any']));
        static::assertNull(MethodNotAllowed::of('not-a-route-array'));
    }

    public function testAcceptsIsCaseInsensitive(): void
    {
        static::assertTrue(MethodNotAllowed::accepts(['methods' => ['GET']], 'get'));
        static::assertFalse(MethodNotAllowed::accepts(['methods' => ['GET']], 'POST'));
    }

    public function testAcceptsAnyMethodWithoutMethods(): void
    {
        static::assertTrue(MethodNotAllowed::accepts(['handler' => 'any'], 'DELETE'));
        static::assertTrue(MethodNotAllowed::accepts('not-a-route-array', 'DELETE'));
    }
}
