<?php

declare(strict_types=1);

namespace SilenZ\Segmatch\OpenApi;

use OpenApi\Attributes as OA;
use SilenZ\Segmatch\MetadataRegistry;
use SilenZ\Segmatch\PathParameter;
use SilenZ\Segmatch\RouteDefinition;

use function array_map;
use function array_values;
use function is_array;
use function is_string;
use function strtolower;

/**
 * Builds the `PathItem`s of an OpenAPI document from a router's declared routes
 * ({@see \SilenZ\Segmatch\RouteTable::definitions()}), reading the metadata shape {@see \SilenZ\Segmatch\Http\Route}
 * stores: a route's `name` becomes its `operationId`, its `tags` become the operation's tags, and its
 * path parameters come straight from {@see RouteDefinition::$pathTemplate} and
 * {@see RouteDefinition::$parameters}.
 *
 *     $paths = PathsGenerator::generate($router->table()->definitions(), $router->table()->registry());
 *     $document = new OA\OpenApi(openapi: '3.1.0', info: new OA\Info(...), paths: $paths);
 *
 * This covers only what segmatch itself knows: paths, methods, names, tags and path parameters.
 * Request/response bodies, security schemes, `info` and `servers` aren't its business; merge them
 * into the document yourself, e.g. by keying off each operation's `operationId` or route name, using
 * the same {@see OA} types.
 *
 * - **`any()` routes** have no declared methods, so every method OpenAPI supports is listed.
 * - **Catch-alls** (`{name*}`, `{name+}`) become a single `{name}` path parameter, since OpenAPI has
 *   no native "rest of the path" placeholder; its actual multi-segment behavior isn't represented.
 * - **`responses` is a required field of an OpenAPI operation**, but segmatch has no notion of what
 *   a route responds with. Each operation gets a placeholder `200` response; replace it yourself.
 */
final class PathsGenerator
{
    private const array ANY_METHODS = ['get', 'put', 'post', 'delete', 'options', 'head', 'patch', 'trace'];

    /**
     * @param iterable<mixed, RouteDefinition> $definitions
     * @param ?MetadataRegistry $registry {@see Http\Routes}' definitions give a {@see MetadataRegistry}
     *     id as their metadata, not the metadata itself — pass {@see \SilenZ\Segmatch\RouteTable::registry()}
     *     along so it can be resolved back; `null` when the metadata is already the real thing, e.g.
     *     from {@see \SilenZ\Segmatch\Http\LazyRoutes}.
     *
     * @return list<OA\PathItem>
     */
    public static function generate(iterable $definitions, ?MetadataRegistry $registry = null): array
    {
        /** @var array<string, OA\PathItem> $pathItems */
        $pathItems = [];

        foreach ($definitions as $definition) {
            $path = $definition->pathTemplate;
            $pathItems[$path] ??= new OA\PathItem(path: $path);
            $pathItem = $pathItems[$path];

            // Metadata is arbitrary user data, so it's mixed by definition.
            // @mago-expect analysis:mixed-assignment
            if ($registry === null) {
                $metadata = $definition->metadata;
            } else {
                /** @var int $id */
                $id = $definition->metadata;
                // @mago-expect analysis:mixed-assignment
                $metadata = $registry->get($id);
            }

            foreach (self::methods($metadata) as $method) {
                self::assign($pathItem, $method, $definition, $metadata);
            }
        }

        return array_values($pathItems);
    }

    /**
     * Builds the operation for `$method` and assigns it to `$pathItem`'s matching property.
     *
     * A method outside the fixed set OpenAPI's `PathItem` can represent (get/put/post/delete/
     * options/head/patch/trace) has no property to hold it and is silently dropped.
     */
    private static function assign(
        OA\PathItem $pathItem,
        string $method,
        RouteDefinition $definition,
        mixed $resolvedMetadata,
    ): void {
        $metadata = is_array($resolvedMetadata) ? $resolvedMetadata : [];

        $tags = null;
        if (is_array($metadata['tags'] ?? null)) {
            /** @var list<string> $tags */
            $tags = $metadata['tags'];
        }

        $args = [
            'operationId' => is_string($metadata['name'] ?? null) ? $metadata['name'] : null,
            'tags' => $tags,
            'parameters' => self::parameters($definition->parameters),
            'responses' => [new OA\Response(response: 200, description: 'OK')],
        ];

        match ($method) {
            'get' => $pathItem->get = new OA\Get(...$args),
            'put' => $pathItem->put = new OA\Put(...$args),
            'post' => $pathItem->post = new OA\Post(...$args),
            'delete' => $pathItem->delete = new OA\Delete(...$args),
            'options' => $pathItem->options = new OA\Options(...$args),
            'head' => $pathItem->head = new OA\Head(...$args),
            'patch' => $pathItem->patch = new OA\Patch(...$args),
            'trace' => $pathItem->trace = new OA\Trace(...$args),
            default => null,
        };
    }

    /**
     * @param list<PathParameter> $parameters
     *
     * @return list<OA\Parameter>|null
     */
    private static function parameters(array $parameters): ?array
    {
        if ($parameters === []) {
            return null;
        }

        return array_map(
            static fn(PathParameter $parameter): OA\Parameter => new OA\PathParameter(
                name: $parameter->name,
                required: $parameter->required,
                schema: new OA\Schema(type: 'string'),
            ),
            $parameters,
        );
    }

    /**
     * @return list<string>
     */
    private static function methods(mixed $metadata): array
    {
        if (is_array($metadata) && is_array($metadata['methods'] ?? null)) {
            /** @var list<string> $methods */
            $methods = $metadata['methods'];

            return array_map(strtolower(...), $methods);
        }

        return self::ANY_METHODS;
    }
}
