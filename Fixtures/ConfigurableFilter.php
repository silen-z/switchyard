<?php

declare(strict_types=1);

namespace SilenZ\Segmatch\Tests\Http\Fixtures;

use Psr\Http\Message\ServerRequestInterface;
use SilenZ\Segmatch\Http\Filter;
use SilenZ\Segmatch\RouteMatch;

/**
 * Always accepts or always rejects, as constructed. Needs a constructor argument, so resolving it
 * with a plain `new` fails; only a container that knows how to build it can supply one.
 */
final class ConfigurableFilter implements Filter
{
    public function __construct(
        private readonly bool $accepts,
    ) {}

    public function accepts(ServerRequestInterface $request, RouteMatch $match): bool
    {
        return $this->accepts;
    }
}
