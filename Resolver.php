<?php

declare(strict_types=1);

namespace SilenZ\Segmatch\Http;

use Psr\Container\ContainerInterface;
use Psr\Http\Message\ResponseFactoryInterface;
use UnexpectedValueException;

use function get_debug_type;
use function is_string;
use function sprintf;

/**
 * Turns what a route's metadata names — a handler, a middleware entry, a filter — into the real thing:
 * a class name or container identifier into what the container resolves it to, anything else
 * (a real instance or closure, already resolved out of a {@see \SilenZ\Segmatch\MetadataRegistry} by
 * the time it reaches here — see {@see \SilenZ\Segmatch\Matcher}) as itself.
 *
 * @internal
 */
final readonly class Resolver
{
    public function __construct(
        private ContainerInterface $container,
    ) {}

    /**
     * One entry as the real thing to run: a class name or container identifier for the container to
     * resolve, or anything else — a real instance or closure — as itself.
     */
    public function entry(mixed $entry): mixed
    {
        return is_string($entry) ? $this->container->get($entry) : $entry;
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
