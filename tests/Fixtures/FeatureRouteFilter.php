<?php

declare(strict_types=1);

namespace SilenZ\Switchyard\Tests\Fixtures;

use Psr\Http\Message\ServerRequestInterface;
use SilenZ\Beeline\RouteMatch;
use SilenZ\Switchyard\RouteFilter;

use function is_array;

/**
 * Accepts a route only while the feature named in its constructor is switched on in the request
 * attributes, as an application's feature-flag filter would.
 */
final class FeatureRouteFilter implements RouteFilter
{
    public function __construct(
        private readonly string $feature,
    ) {}

    public function accepts(RouteMatch $match, ServerRequestInterface $request): bool
    {
        // PSR-7 attributes are mixed by definition; the is_array() check below narrows it.
        $features = $request->getAttribute('features');

        return is_array($features) && ($features[$this->feature] ?? false) === true;
    }
}
