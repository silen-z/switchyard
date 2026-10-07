<?php

declare(strict_types=1);

namespace SilenZ\Switchyard\Tests\Fixtures;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

use function implode;
use function in_array;
use function strtoupper;

/**
 * A basic CORS middleware, declared with `$routes->middleware(new CorsMiddleware(...))` on the root
 * `Http\Routes`, so it wraps every outcome of `$builder->handler($request)->handle($request)`, not
 * just matched routes — see `Http\Routes::middleware()`. It reads the allowed methods from the
 * response's `Allow` header, built by `Handler\AllowedMethodsHandler` in exactly the format
 * `Access-Control-Allow-Methods` wants, rather than the `MethodNotAllowed::class` request attribute:
 * that attribute only exists inside `Handler`'s own Relay stack, invisible to anything
 * wrapping it from outside.
 *
 * Requests without an `Origin`, or from an origin not listed, pass through untouched. A preflight
 * (OPTIONS with `Access-Control-Request-Method`, that no route took) gets the default 200 decorated
 * with the allow headers; any other response gets `Access-Control-Allow-Origin`.
 */
final readonly class CorsMiddleware implements MiddlewareInterface
{
    /**
     * @param list<string> $origins allowed origins, e.g. `https://app.example`
     * @param list<string> $headers request headers a preflight may ask for
     */
    public function __construct(
        private array $origins,
        private array $headers = [],
        private int $maxAge = 600,
    ) {}

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $origin = $request->getHeaderLine('Origin');
        $response = $handler->handle($request);
        if ($origin === '' || !in_array($origin, $this->origins, strict: true)) {
            return $response;
        }

        $response = $response->withHeader('Access-Control-Allow-Origin', $origin)->withAddedHeader('Vary', 'Origin');

        if (
            strtoupper($request->getMethod()) !== 'OPTIONS'
            || !$request->hasHeader('Access-Control-Request-Method')
            || !$response->hasHeader('Allow')
        ) {
            // Not a preflight, or one a route answers itself.
            return $response;
        }

        return $response
            ->withHeader('Access-Control-Allow-Methods', $response->getHeaderLine('Allow'))
            ->withHeader('Access-Control-Allow-Headers', implode(', ', $this->headers))
            ->withHeader('Access-Control-Max-Age', (string) $this->maxAge);
    }
}
