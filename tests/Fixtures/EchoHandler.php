<?php

declare(strict_types=1);

namespace SilenZ\Switchyard\Tests\Fixtures;

use Nyholm\Psr7\Response;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use SilenZ\Switchyard\Found;

use function json_encode;

use const JSON_THROW_ON_ERROR;

/**
 * Stands in for any route handler: answers with the `Found` it was given as a JSON body, and with
 * its own identifier in an "X-Handler" header, so tests can see which route matched through a plain
 * response.
 */
final class EchoHandler implements RequestHandlerInterface
{
    public function __construct(
        private readonly string $id = '',
    ) {}

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $headers = [
            'Content-Type' => 'application/json',
            'X-Method' => $request->getMethod(),
            // Headers survive HEAD responses, which lose the body.
            'X-Handler' => $this->id,
        ];

        return new Response(200, $headers, json_encode($request->getAttribute(Found::class), JSON_THROW_ON_ERROR));
    }
}
