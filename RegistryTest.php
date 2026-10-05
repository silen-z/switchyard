<?php

declare(strict_types=1);

namespace SilenZ\Segmatch\Tests\Http;

use PHPUnit\Framework\TestCase;
use SilenZ\Segmatch\Exception\InvalidRouteException;
use SilenZ\Segmatch\Http\Registry;
use SilenZ\Segmatch\Tests\Fixtures\Method;
use stdClass;

final class RegistryTest extends TestCase
{
    public function testPlainValuesPassThroughUnchanged(): void
    {
        $registry = new Registry();

        static::assertSame('show', $registry->wrap('show', 'test'));
        static::assertSame(4.2, $registry->wrap(4.2, 'test'));
        static::assertNull($registry->wrap(null, 'test'));
        static::assertSame(Method::Get, $registry->wrap(Method::Get, 'test'));
        static::assertSame(['a', 1, null], $registry->wrap(['a', 1, null], 'test'));
    }

    public function testAnIntegerIsRejectedSinceItWouldReadAsAnId(): void
    {
        $this->expectException(InvalidRouteException::class);
        $this->expectExceptionMessageMatches('/^Route "\/a" handler cannot be an integer \(42\)/');

        new Registry()->wrap(42, 'Route "/a" handler');
    }

    public function testNonPlainValuesAreWrappedIntoAnId(): void
    {
        $registry = new Registry();
        $object = new stdClass();

        /** @var int $id */
        $id = $registry->wrap($object, 'test');

        static::assertIsInt($id);
        static::assertSame($object, $registry->get($id));
    }

    public function testAnArrayContainingAnyNonPlainValueIsWrappedWhole(): void
    {
        $registry = new Registry();
        $mixed = [new stdClass(), 'show'];

        /** @var int $id */
        $id = $registry->wrap($mixed, 'test');

        static::assertIsInt($id);
        static::assertSame($mixed, $registry->get($id));
    }

    public function testIdsAreAssignedInWrappingOrder(): void
    {
        $registry = new Registry();
        $first = new stdClass();
        $second = new stdClass();

        /** @var int $firstId */
        $firstId = $registry->wrap($first, 'test');
        /** @var int $secondId */
        $secondId = $registry->wrap($second, 'test');

        static::assertSame($first, $registry->get($firstId));
        static::assertSame($second, $registry->get($secondId));
        static::assertNotSame($firstId, $secondId);
    }

    public function testClosuresAreWrapped(): void
    {
        $registry = new Registry();
        $closure = static fn(): string => 'x';

        /** @var int $id */
        $id = $registry->wrap($closure, 'test');

        static::assertIsInt($id);
        static::assertSame($closure, $registry->get($id));
    }
}
