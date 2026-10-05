<?php

declare(strict_types=1);

namespace SilenZ\Segmatch\Http;

use Closure;
use SilenZ\Segmatch\RouteTable;

use function array_values;
use function is_array;

/**
 * A {@see Routes} tree as the {@see RouteTable} `Router` takes: the tree's routes as its definitions,
 * and the root's own middleware as its metadata, `['middleware' => [...]]`.
 *
 * The root's middleware belongs to no route — it wraps every outcome, the not-found and
 * method-not-allowed/OPTIONS answers included — so it isn't baked into any route's metadata. Kept as
 * the table's metadata instead, it's cached with the routes and read back with
 * {@see \SilenZ\Segmatch\Router::tableMetadata()}, without the tree having been declared on that
 * request at all.
 *
 * The tree comes from a closure, called at most once and only when the definitions or the metadata
 * are actually needed — on a cache miss — so both are read from the same tree.
 *
 * @internal built by {@see Routes::table()}, or {@see lazy()} for {@see RoutesHandlerBuilder::lazyRoutes()}
 */
final class RoutesTable extends RouteTable
{
    private ?Routes $routes = null;

    /**
     * @param Closure(): Routes $tree
     */
    public function __construct(
        private readonly Closure $tree,
        private readonly ?string $cacheKey,
    ) {}

    /**
     * A table for routes declared lazily: `$define` declares them on a fresh tree with a
     * {@see Registry::strict()} registry, and only runs when the definitions or the metadata are
     * needed, i.e. on a cache miss.
     *
     * @param Closure(Routes): void $define
     */
    public static function lazy(Closure $define, ?string $cacheKey): self
    {
        return new self(static function () use ($define): Routes {
            $routes = new Routes(Registry::strict());
            $define($routes);

            return $routes;
        }, $cacheKey);
    }

    public function cacheKey(): ?string
    {
        return $this->cacheKey;
    }

    public function definitions(): array
    {
        return $this->tree()->definitions();
    }

    /**
     * @return array{middleware: list<mixed>}
     */
    public function metadata(): array
    {
        return ['middleware' => $this->tree()->ownMiddleware()];
    }

    /**
     * The root's middleware out of a table's metadata as {@see metadata()} gave it, e.g. read back
     * with {@see \SilenZ\Segmatch\Router::tableMetadata()}; none for anything else.
     *
     * @return list<mixed>
     */
    public static function middlewareOf(mixed $metadata): array
    {
        if (!is_array($metadata) || !is_array($metadata['middleware'] ?? null)) {
            return [];
        }

        return array_values($metadata['middleware']);
    }

    private function tree(): Routes
    {
        return $this->routes ??= ($this->tree)();
    }
}
