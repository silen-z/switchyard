<?php

declare(strict_types=1);

namespace SilenZ\Segmatch\Tests\Http;

use PHPUnit\Framework\TestCase;
use SilenZ\Segmatch\Http\Registry;
use SilenZ\Segmatch\Tests\Fixtures\Method;
use stdClass;

final class RegistryTest extends TestCase
{
    public function testPlainValuesPassThroughUnchanged(): void
    {
        $registry = new Registry();

        static::assertSame('show', $registry->wrap('show'));
        static::assertSame(42, $registry->wrap(42));
        static::assertNull($registry->wrap(null));
        static::assertSame(Method::Get, $registry->wrap(Method::Get));
        static::assertSame(['a', 1, null], $registry->wrap(['a', 1, null]));
    }

    public function testNonPlainValuesAreWrappedIntoAnId(): void
    {
        $registry = new Registry();
        $object = new stdClass();

        /** @var int $id */
        $id = $registry->wrap($object);

        static::assertIsInt($id);
        static::assertSame($object, $registry->get($id));
    }

    public function testAnArrayContainingAnyNonPlainValueIsWrappedWhole(): void
    {
        $registry = new Registry();
        $mixed = [new stdClass(), 'show'];

        /** @var int $id */
        $id = $registry->wrap($mixed);

        static::assertIsInt($id);
        static::assertSame($mixed, $registry->get($id));
    }

    public function testIdsAreAssignedInWrappingOrder(): void
    {
        $registry = new Registry();
        $first = new stdClass();
        $second = new stdClass();

        /** @var int $firstId */
        $firstId = $registry->wrap($first);
        /** @var int $secondId */
        $secondId = $registry->wrap($second);

        static::assertSame($first, $registry->get($firstId));
        static::assertSame($second, $registry->get($secondId));
        static::assertNotSame($firstId, $secondId);
    }

    public function testClosuresAreWrapped(): void
    {
        $registry = new Registry();
        $closure = static fn(): string => 'x';

        /** @var int $id */
        $id = $registry->wrap($closure);

        static::assertIsInt($id);
        static::assertSame($closure, $registry->get($id));
    }
}
