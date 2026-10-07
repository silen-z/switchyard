<?php

declare(strict_types=1);

namespace SilenZ\Switchyard\Tests\Fixtures;

use Psr\Container\ContainerInterface;
use RuntimeException;

use function array_key_exists;
use function sprintf;

/**
 * A trivial PSR-11 container backed by a fixed map, standing in for an application's real one.
 */
final class ArrayContainer implements ContainerInterface
{
    /**
     * @param array<string, object> $services
     */
    public function __construct(
        private readonly array $services,
    ) {}

    public function get(string $id): object
    {
        if (!array_key_exists($id, $this->services)) {
            throw new RuntimeException(sprintf('Service "%s" not found.', $id));
        }

        return $this->services[$id];
    }

    public function has(string $id): bool
    {
        return array_key_exists($id, $this->services);
    }
}
