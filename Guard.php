<?php

declare(strict_types=1);

namespace SilenZ\Segmatch\Http;

/**
 * A condition a route attaches to itself, checked while matching.
 *
 * Routes reference guards by class name together with plain-data configuration, so both survive the
 * route cache. {@see Guards::for()} calls them for every candidate route of a request; a guard that
 * returns false makes the route behave as if it didn't exist, and matching moves on.
 *
 * Guards decide whether a route applies to the request (method, parameter format, a feature switch),
 * never who is asking: authentication and permissions belong to middleware. They may run several
 * times per request, so keep them cheap and free of side effects.
 */
interface Guard
{
    /**
     * @param mixed $config the configuration the route was declared with
     * @param array<string, string> $params the route's URL-decoded parameters for this request
     */
    public static function accepts(mixed $config, Request $request, array $params): bool;
}
