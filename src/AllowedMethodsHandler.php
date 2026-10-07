<?php

declare(strict_types=1);

namespace SilenZ\Switchyard;

use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

use function implode;
use function strtoupper;

/**
 * {@see HandlerBuilder}'s answer when routes exist for the path but not for the request's method:
 * an empty response with the `Allow` header, from the `MethodNotAllowed::class` request attribute.
 * It's a 405, or a 200 for an OPTIONS request, which asks for exactly that list.
 */
final readonly class AllowedMethodsHandler implements RequestHandlerInterface
{
    public function __construct(
        private ResponseFactoryInterface $responseFactory,
    ) {}

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $status = strtoupper($request->getMethod()) === 'OPTIONS' ? 200 : 405;
        $response = $this->responseFactory->createResponse($status);
        // @mago-expect analysis:mixed-assignment
        $result = $request->getAttribute(MethodNotAllowed::class);

        return $result instanceof MethodNotAllowed
            ? $response->withHeader('Allow', implode(', ', $result->allowed))
            : $response;
    }
}
