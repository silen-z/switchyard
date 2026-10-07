<?php

declare(strict_types=1);

namespace SilenZ\Segmatch\Http;

use Psr\Container\ContainerInterface;
use Psr\Http\Message\ResponseFactoryInterface;
use SilenZ\Segmatch\InstanceRegistry;
use UnexpectedValueException;

use function get_debug_type;
use function is_int;
use function is_string;
use function sprintf;

/**
 * Turns what a route's metadata names — a handler, a middleware entry, a filter — into the real thing:
 * an {@see InstanceRegistry} id into the instance or closure it stands for, a class name or container
 * identifier into what the container resolves it to.
 *
 * `$registry` must be the one the routes were declared with — see {@see HandlerBuilder}.
 *
 * @internal
 */
final readonly class Resolver
{
    public function __construct(
        private InstanceRegistry $registry,
        private ContainerInterface $container,
    ) {}

    /**
     * One entry as the real thing to run: an {@see InstanceRegistry} id standing in for an instance or
     * closure the routes were declared with, a class name or container identifier for the container to
     * resolve, or anything else as itself.
     */
    public function entry(mixed $entry): mixed
    {
        if (is_int($entry)) {
            return $this->registry->get($entry);
        }

        if (!is_string($entry)) {
            return $entry;
        }

        return $this->container->get($entry);
    }

    /**
     * A filter entry as its {@see RouteFilter}.
     *
     * @throws UnexpectedValueException when it resolves to anything else — only possible for a
     *                                  container identifier, since a class name or an instance is
     *                                  checked when the route is declared
     */
    public function filter(mixed $entry): RouteFilter
    {
        // @mago-expect analysis:mixed-assignment
        $filter = $this->entry($entry);
        if (!$filter instanceof RouteFilter) {
            throw new UnexpectedValueException(sprintf(
                'Filter "%s" resolved to %s, which does not implement %s.',
                is_string($entry) ? $entry : get_debug_type($entry),
                get_debug_type($filter),
                RouteFilter::class,
            ));
        }

        return $filter;
    }

    /**
     * The container's {@see ResponseFactoryInterface}, for building a response with nothing already
     * on hand to base one on, e.g. {@see HandlerBuilder}'s own trailing-slash {@see RedirectHandler}.
     *
     * @throws UnexpectedValueException when it resolves to anything else
     */
    public function responseFactory(): ResponseFactoryInterface
    {
        // @mago-expect analysis:mixed-assignment
        $factory = $this->entry(ResponseFactoryInterface::class);
        if (!$factory instanceof ResponseFactoryInterface) {
            throw new UnexpectedValueException(sprintf(
                '%s resolved to %s, which is not a %s.',
                ResponseFactoryInterface::class,
                get_debug_type($factory),
                ResponseFactoryInterface::class,
            ));
        }

        return $factory;
    }
}
