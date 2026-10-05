<?php

declare(strict_types=1);

namespace SilenZ\Segmatch\Tests\Http\Fixtures;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use SilenZ\Segmatch\Http\MethodNotAllowed;

use function implode;
use function in_array;
use function strtoupper;

/**
 * A basic CORS middleware, added with `HandlerResolver::addMiddleware()`, to show the mechanism an
 * application would use: it runs for every outcome, and on the resolver's default OPTIONS answer it
 * sees the allowed methods as the `MethodNotAllowed::class` request attribute, filters included.
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

        // @mago-expect analysis:mixed-assignment
        $allowed = $request->getAttribute(MethodNotAllowed::class);
        if (
            strtoupper($request->getMethod()) !== 'OPTIONS'
            || !$request->hasHeader('Access-Control-Request-Method')
            || !$allowed instanceof MethodNotAllowed
        ) {
            // Not a preflight, or one a route answers itself.
            return $response;
        }

        return $response
            ->withHeader('Access-Control-Allow-Methods', implode(', ', $allowed->allowed))
            ->withHeader('Access-Control-Allow-Headers', implode(', ', $this->headers))
            ->withHeader('Access-Control-Max-Age', (string) $this->maxAge);
    }
}
