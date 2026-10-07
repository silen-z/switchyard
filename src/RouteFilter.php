<?php

declare(strict_types=1);

namespace SilenZ\Switchyard;

use Psr\Http\Message\ServerRequestInterface;
use SilenZ\Beeline\RouteMatch;

/**
 * A condition a route attaches to itself, checked while matching.
 *
 * A route usually references a filter by class name, so it survives the route cache; {@see
 * HandlerBuilder} resolves one instance per filter class from the container, which wires up
 * whatever dependencies that class always needs, the same way for every route that uses it. A route
 * may instead be given a ready instance directly
 * ({@see Route::filter()}), which skips the container and bakes its own configuration into its
 * constructor instead — the only way to vary one filter's behavior per route, since a class name gives
 * the container no way to tell routes apart. Either way the instance is kept in the route's own
 * metadata, transparently, as part of what {@see Route::definition()} hands its
 * {@see \SilenZ\Beeline\MetadataRegistry} as one unit.
 *
 * Either way, {@see HandlerBuilder} calls {@see accepts()} for every candidate route of a request. A
 * filter that returns false makes the route behave as if it didn't exist, and matching moves on.
 *
 * Filters decide whether a route applies to the request (host, content type, a feature switch), never
 * who is asking: authentication and permissions belong to middleware. HTTP methods are matched
 * separately, not through a filter; see {@see MethodNotAllowed}. Filters may run several times per
 * request, so keep them cheap and free of side effects.
 *
 * `$match` is the candidate being decided, the same {@see \SilenZ\Beeline\RouteMatch} {@see
 * \SilenZ\Beeline\Router::match()} itself works with: `$match->route` is the candidate's metadata
 * (mixed, as declared), `$match->params` its URL-decoded parameter values. Most filters only need
 * `$request`; `$match` mainly earns its keep when what decides a route exists is baked into the path
 * itself, e.g. a version or tenant segment, rather than general request state.
 */
interface RouteFilter
{
    public function accepts(RouteMatch $match, ServerRequestInterface $request): bool;
}
