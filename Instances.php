<?php

declare(strict_types=1);

namespace SilenZ\Segmatch\Http;

use Psr\Container\ContainerInterface;

use function is_int;
use function is_string;

/**
 * Resolves a filter, middleware or handler identifier to an instance, for {@see HandlerResolver}: a
 * {@see Registry} id gives back the real instance or closure it stands in for; a class name or
 * container identifier is resolved from the container given to this constructor, or a plain
 * `new $entry()` without one. Relay passes every stack entry through {@see of()} too, so anything
 * already resolved comes back as is.
 */
final readonly class Instances
{
    public function __construct(
        private ?ContainerInterface $container,
        private Registry $registry,
    ) {}

    public function of(mixed $entry): mixed
    {
        if (is_int($entry)) {
            return $this->registry->get($entry);
        }

        if (!is_string($entry)) {
            return $entry;
        }

        return $this->container?->get($entry) ?? new $entry();
    }
}
