<?php

declare(strict_types=1);

namespace SilenZ\Segmatch\Http;

use Psr\Http\Message\ServerRequestInterface;

/**
 * A condition a route attaches to itself, checked while matching.
 *
 * Routes reference guards by class name together with plain-data configuration, so both survive the
 * route cache; the instance itself never does. {@see HandlerResolver} resolves one instance per guard
 * class from the container given to it (or builds a plain `new $guard()` without one) and calls
 * {@see accepts()} for every candidate route of a request. A guard that returns false makes the route
 * behave as if it didn't exist, and matching moves on.
 *
 * Guards decide whether a route applies to the request (host, content type, a feature switch), never
 * who is asking: authentication and permissions belong to middleware. HTTP methods are matched
 * separately, not through a guard; see {@see MethodNotAllowed}. Guards may run several times per
 * request, so keep them cheap and free of side effects.
 */
interface Guard
{
    /**
     * @param mixed $config the configuration the route was declared with
     * @param array<string, string> $params the route's URL-decoded parameters for this request
     */
    public function accepts(mixed $config, ServerRequestInterface $request, array $params): bool;
}
