<?php

declare(strict_types=1);

namespace SilenZ\Segmatch\Http;

use Psr\Http\Message\ServerRequestInterface;

/**
 * A condition a route attaches to itself, checked while matching.
 *
 * A route usually references a guard by class name together with plain-data configuration, so both
 * survive the route cache; {@see HandlerResolver} resolves one instance per guard class from the
 * container given to it (or builds a plain `new $guard()` without one). A route may instead be given a
 * ready instance directly ({@see Route::guard()}), which skips the container and the cache — the
 * instance is kept in the route's {@see Registry} instead. That instance can bake its own
 * configuration into its constructor, so `$config` mainly earns its keep for the class-name form,
 * where the same guard class is shared across routes that each need it configured differently (e.g. a
 * feature name).
 *
 * Either way, {@see HandlerResolver} calls {@see accepts()} for every candidate route of a request. A
 * guard that returns false makes the route behave as if it didn't exist, and matching moves on.
 *
 * Guards decide whether a route applies to the request (host, content type, a feature switch), never
 * who is asking: authentication and permissions belong to middleware. HTTP methods are matched
 * separately, not through a guard; see {@see MethodNotAllowed}. Guards may run several times per
 * request, so keep them cheap and free of side effects.
 */
interface Guard
{
    /**
     * @param mixed $config the configuration the route was declared with, null when the guard was
     *                       given as an instance and none was passed alongside it
     * @param array<string, string> $params the route's URL-decoded parameters for this request
     */
    public function accepts(mixed $config, ServerRequestInterface $request, array $params): bool;
}
