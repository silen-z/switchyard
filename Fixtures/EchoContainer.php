<?php

declare(strict_types=1);

namespace SilenZ\Segmatch\Tests\Http\Fixtures;

use Psr\Container\ContainerInterface;

use function array_key_exists;
use function class_exists;

/**
 * Resolves the given services, then any class by name, and every other identifier — the plain
 * strings tests use as route handlers — to an {@see EchoHandler}. Middleware identifiers must be
 * given as services.
 */
final class EchoContainer implements ContainerInterface
{
    /**
     * @param array<string, object> $services
     */
    public function __construct(
        private readonly array $services = [],
    ) {}

    public function get(string $id): object
    {
        if (array_key_exists($id, $this->services)) {
            return $this->services[$id];
        }

        // @mago-expect analysis:unknown-class-instantiation
        return class_exists($id) ? new $id() : new EchoHandler($id);
    }

    public function has(string $id): bool
    {
        return true;
    }
}
