<?php

declare(strict_types=1);

namespace SilenZ\Segmatch\Tests\Http\Fixtures;

use SilenZ\Segmatch\Http\Guard;
use SilenZ\Segmatch\Http\Request;

use function is_array;

/**
 * Accepts a route only while the feature named in its configuration is switched on in the request
 * attributes, as an application's feature-flag guard would.
 */
final class FeatureGuard implements Guard
{
    public static function accepts(mixed $config, Request $request, array $params): bool
    {
        return (
            is_array($request->attributes['features'] ?? null)
            && ($request->attributes['features'][$config] ?? false) === true
        );
    }
}
