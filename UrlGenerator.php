<?php

declare(strict_types=1);

namespace SilenZ\Segmatch\Http;

use SilenZ\Segmatch\Exception\UrlGenerationException;
use SilenZ\Segmatch\Internal\PathParser;
use SilenZ\Segmatch\Internal\Segment;
use SilenZ\Segmatch\Internal\SegmentType;
use SilenZ\Segmatch\Router;
use Stringable;

use function array_key_exists;
use function array_map;
use function explode;
use function get_debug_type;
use function http_build_query;
use function implode;
use function is_array;
use function is_int;
use function is_string;
use function rawurlencode;
use function sprintf;

use const PHP_QUERY_RFC3986;

/**
 * Generates URLs for routes named with {@see Route::name()}:
 *
 *     $urls = new UrlGenerator($router);
 *     $urls->url('users.show', ['id' => 42]);               // "/api/users/42"
 *     $urls->url('users.show', ['id' => 42, 'tab' => 'x']); // "/api/users/42?tab=x"
 *
 * Named routes keep their path in the route metadata, so this works from a cached route table too.
 * The index of names is built on the first call.
 */
final class UrlGenerator
{
    /** @var ?array<string, string> route name => path */
    private ?array $paths = null;

    /** @var array<string, non-empty-list<Segment>> route name => parsed path */
    private array $segments = [];

    public function __construct(
        private readonly Router $router,
    ) {}

    /**
     * Parameters fill the placeholders of the route's path and must all be given; the rest become
     * the query string. Values are URL-encoded; a catch-all's value keeps its slashes.
     *
     * @param array<string, mixed> $params
     *
     * @throws UrlGenerationException when the route doesn't exist or a parameter is missing or invalid
     */
    public function url(string $name, array $params = []): string
    {
        $parts = [];
        foreach ($this->segments[$name] ??= PathParser::parse($this->path($name)) as $segment) {
            if ($segment->type === SegmentType::Static) {
                $parts[] = $segment->value;
                continue;
            }

            $value = self::value($name, $segment->value, $params);
            unset($params[$segment->value]);

            if ($value === '') {
                if ($segment->type !== SegmentType::CatchAllZero) {
                    throw new UrlGenerationException(sprintf(
                        'Cannot generate a URL for route "%s": parameter "%s" must not be empty.',
                        $name,
                        $segment->value,
                    ));
                }

                // An empty {name*} matches without its slash too: "/assets", not "/assets/".
                continue;
            }

            $parts[] = $segment->type === SegmentType::Param
                ? rawurlencode($value)
                : implode('/', array_map(rawurlencode(...), explode('/', $value)));
        }

        $url = '/' . implode('/', $parts);
        $query = http_build_query($params, numeric_prefix: '', arg_separator: '&', encoding_type: PHP_QUERY_RFC3986);

        return $query === '' ? $url : $url . '?' . $query;
    }

    /**
     * @param array<string, mixed> $params
     *
     * @throws UrlGenerationException
     */
    private static function value(string $name, string $param, array $params): string
    {
        if (!array_key_exists($param, $params)) {
            throw new UrlGenerationException(sprintf(
                'Cannot generate a URL for route "%s": missing parameter "%s".',
                $name,
                $param,
            ));
        }

        // @mago-expect analysis:mixed-assignment
        $value = $params[$param];
        if (is_string($value) || is_int($value) || $value instanceof Stringable) {
            return (string) $value;
        }

        throw new UrlGenerationException(sprintf(
            'Cannot generate a URL for route "%s": parameter "%s" must be a string, int or Stringable, got %s.',
            $name,
            $param,
            get_debug_type($value),
        ));
    }

    /**
     * @throws UrlGenerationException
     */
    private function path(string $name): string
    {
        if ($this->paths === null) {
            $this->paths = [];
            // @mago-expect analysis:mixed-assignment
            foreach ($this->router->matcher()->metadata() as $route) {
                if (!is_array($route) || !is_string($route['name'] ?? null) || !is_string($route['path'] ?? null)) {
                    continue;
                }

                $this->paths[$route['name']] = $route['path'];
            }
        }

        return $this->paths[$name] ?? throw new UrlGenerationException(sprintf('No route is named "%s".', $name));
    }
}
