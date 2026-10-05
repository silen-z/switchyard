<?php

declare(strict_types=1);

namespace SilenZ\Segmatch\Tests\Http\Fixtures;

use Psr\Http\Message\ServerRequestInterface;
use SilenZ\Segmatch\Http\Guard;

use function is_array;

/**
 * Accepts a route only while the feature named in its constructor is switched on in the request
 * attributes, as an application's feature-flag guard would.
 */
final class FeatureGuard implements Guard
{
    public function __construct(
        private readonly string $feature,
    ) {}

    public function accepts(ServerRequestInterface $request, array $params): bool
    {
        $features = $request->getAttribute('features');

        return is_array($features) && ($features[$this->feature] ?? false) === true;
    }
}
