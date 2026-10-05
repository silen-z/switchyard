<?php

declare(strict_types=1);

namespace SilenZ\Segmatch\Http;

use Psr\Http\Message\ServerRequestInterface;

/**
 * A condition a route attaches to itself, checked while matching.
 *
 * A route usually references a guard by class name, so it survives the route cache; {@see
 * HandlerResolver} resolves one instance per guard class from the container given to it (or builds a
 * plain `new $guard()` without one), which wires up whatever dependencies that class always needs,
 * the same way for every route that uses it. A route may instead be given a ready instance directly
 * ({@see Route::guard()}), which skips the container and bakes its own configuration into its
 * constructor instead — the only way to vary one guard's behavior per route, since a class name gives
 * the container no way to tell routes apart. Either way the instance is kept in the route's
 * {@see Registry}, transparently.
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
     * @param array<string, string> $params the route's URL-decoded parameters for this request
     */
    public function accepts(ServerRequestInterface $request, array $params): bool;
}
