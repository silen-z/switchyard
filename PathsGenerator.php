<?php

declare(strict_types=1);

namespace SilenZ\Segmatch\OpenApi;

use SilenZ\Segmatch\Internal\Segment;
use SilenZ\Segmatch\Internal\SegmentType;
use SilenZ\Segmatch\RouteDefinition;

use function array_map;
use function implode;
use function is_array;
use function is_string;
use function strtolower;

/**
 * Builds the `paths` object of an OpenAPI document from a router's declared routes
 * ({@see \SilenZ\Segmatch\Router::definitions()}), reading the metadata shape {@see \SilenZ\Segmatch\Http\Route}
 * stores: a route's `name` becomes its `operationId`, its `tags` become the operation's tags, and its
 * path parameters come straight from the segments the path was declared with.
 *
 *     $paths = PathsGenerator::generate($router->definitions());
 *     $document = ['openapi' => '3.1.0', 'info' => [...], ...$paths];
 *
 * This covers only what segmatch itself knows: paths, methods, names, tags and path parameters.
 * Request/response bodies, security schemes, `info` and `servers` aren't its business; merge them
 * into the document yourself, e.g. by keying off each operation's `operationId` or route name.
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
     *
     * @return array{paths: array<string, array<string, array<string, mixed>>>}
     */
    public static function generate(iterable $definitions): array
    {
        $paths = [];
        foreach ($definitions as $definition) {
            $path = self::path($definition->segments);
            $operation = self::operation($definition);

            foreach (self::methods($definition->metadata) as $method) {
                $paths[$path][$method] = $operation;
            }
        }

        return ['paths' => $paths];
    }

    /**
     * @param non-empty-list<Segment> $segments
     */
    private static function path(array $segments): string
    {
        $parts = [];
        foreach ($segments as $segment) {
            $parts[] = $segment->type === SegmentType::Static ? $segment->value : '{' . $segment->value . '}';
        }

        return '/' . implode('/', $parts);
    }

    /**
     * @return array<string, mixed>
     */
    private static function operation(RouteDefinition $definition): array
    {
        $metadata = is_array($definition->metadata) ? $definition->metadata : [];

        $operation = ['responses' => ['200' => ['description' => 'OK']]];

        if (is_string($metadata['name'] ?? null)) {
            $operation['operationId'] = $metadata['name'];
        }

        if (is_array($metadata['tags'] ?? null)) {
            $operation['tags'] = $metadata['tags'];
        }

        $parameters = self::parameters($definition->segments);
        if ($parameters !== []) {
            $operation['parameters'] = $parameters;
        }

        return $operation;
    }

    /**
     * @param non-empty-list<Segment> $segments
     *
     * @return list<array<string, mixed>>
     */
    private static function parameters(array $segments): array
    {
        $parameters = [];
        foreach ($segments as $segment) {
            if ($segment->type === SegmentType::Static) {
                continue;
            }

            $parameters[] = [
                'name' => $segment->value,
                'in' => 'path',
                'required' => $segment->type !== SegmentType::CatchAllZero,
                'schema' => ['type' => 'string'],
            ];
        }

        return $parameters;
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
