<?php

declare(strict_types=1);

namespace SilenZ\Segmatch\Http;

use SilenZ\Segmatch\Exception\InvalidRouteException;

use function array_key_exists;
use function array_values;
use function is_array;
use function preg_match;
use function restore_error_handler;
use function set_error_handler;
use function sprintf;
use function str_replace;

/**
 * One route declaration: HTTP methods, a path relative to the enclosing groups, and a handler.
 * Returned by {@see RouteCollector} so it can be refined fluently:
 *
 *     $r->get('/users/{id}', [UserController::class, 'show'])
 *         ->name('users.show')
 *         ->middleware('audit')
 *         ->where('id', '\d+');
 *
 * The handler and middleware end up in the route cache, so they must be plain data (strings, arrays,
 * enums, ...), not closures or objects.
 */
final class Route
{
    private ?string $name = null;

    /** @var list<mixed> */
    private array $middleware = [];

    /** @var array<string, string> */
    private array $where = [];

    /**
     * @param non-empty-list<string> $methods upper-case HTTP methods, or ['*'] for any method
     */
    public function __construct(
        private readonly array $methods,
        private readonly string $path,
        private readonly mixed $handler,
    ) {}

    /**
     * Names the route. Names are unique across all routes.
     */
    public function name(string $name): self
    {
        if ($name === '') {
            throw new InvalidRouteException(sprintf('Route "%s" cannot have an empty name.', $this->path));
        }

        $this->name = $name;

        return $this;
    }

    /**
     * Adds middleware that runs after the middleware of the enclosing groups.
     *
     * @param mixed $middleware one middleware, or a list of them
     */
    public function middleware(mixed $middleware): self
    {
        $this->middleware = [
            ...$this->middleware,
            ...(is_array($middleware) ? array_values($middleware) : [$middleware]),
        ];

        return $this;
    }

    /**
     * Restricts a parameter to values matching a regular expression (without delimiters or anchors,
     * e.g. `\d+`). Routes whose constraint doesn't hold are skipped while matching, so another route
     * with the same path shape can take the request.
     */
    public function where(string $parameter, string $pattern): self
    {
        $error = null;
        set_error_handler(static function (int $_level, string $message) use (&$error): bool {
            $error = $message;

            return true;
        });

        try {
            $valid = preg_match(self::regex($pattern), subject: '') !== false;
        } finally {
            restore_error_handler();
        }

        if (!$valid) {
            throw new InvalidRouteException(sprintf(
                'Route "%s" has an invalid pattern "%s" for parameter "%s"%s',
                $this->path,
                $pattern,
                $parameter,
                $error !== null ? ': ' . $error : '.',
            ));
        }

        $this->where[$parameter] = $pattern;

        return $this;
    }

    /**
     * Wraps a `where()` pattern into the full regular expression used to check a parameter value.
     */
    public static function regex(string $pattern): string
    {
        return '#^(?:' . str_replace(search: '#', replace: '\#', subject: $pattern) . ')$#D';
    }

    /**
     * @internal
     */
    public function path(): string
    {
        return $this->path;
    }

    /**
     * @internal
     */
    public function routeName(): ?string
    {
        return $this->name;
    }

    /**
     * The metadata stored for the route:
     *
     *     [
     *         'methods'    => ['GET'],             // or ['*'] for any method
     *         'handler'    => [UserController::class, 'show'],
     *         'middleware' => ['api', 'auth'],     // groups' middleware first, outermost first
     *         'name'       => 'users.show',        // only when named
     *         'where'      => ['id' => '\d+'],     // only when constrained
     *     ]
     *
     * @internal
     *
     * @param list<mixed> $groupMiddleware
     * @param list<string> $parameters names of the parameters in the full path
     *
     * @return array{methods: non-empty-list<string>, handler: mixed, middleware: list<mixed>, name?: string, where?: array<string, string>}
     */
    public function metadata(string $fullPath, array $groupMiddleware, array $parameters): array
    {
        $metadata = [
            'methods' => $this->methods,
            'handler' => $this->handler,
            'middleware' => [...$groupMiddleware, ...$this->middleware],
        ];

        if ($this->name !== null) {
            $metadata['name'] = $this->name;
        }

        if ($this->where !== []) {
            $known = [];
            foreach ($parameters as $parameter) {
                $known[$parameter] = true;
            }

            foreach ($this->where as $parameter => $_pattern) {
                if (!array_key_exists($parameter, $known)) {
                    throw new InvalidRouteException(sprintf(
                        'Route "%s" constrains parameter "%s", which its path does not have.',
                        $fullPath,
                        $parameter,
                    ));
                }
            }

            $metadata['where'] = $this->where;
        }

        return $metadata;
    }
}
