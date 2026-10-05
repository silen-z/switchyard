<?php

declare(strict_types=1);

namespace SilenZ\Segmatch\Tests\Http\Fixtures;

use Nyholm\Psr7\Factory\Psr17Factory;
use Psr\Container\ContainerInterface;
use SilenZ\Segmatch\Http\AllowedMethodsHandler;
use SilenZ\Segmatch\Http\HeadMiddleware;
use SilenZ\Segmatch\Http\NotFoundHandler;

use function array_key_exists;
use function class_exists;

/**
 * Resolves the given services, then any class by name, and every other identifier — the plain
 * strings tests use as route handlers — to an {@see EchoHandler}. Middleware identifiers must be
 * given as services. `Http\RoutesHandlerBuilder`'s own default handlers need a `Psr17Factory`
 * constructor argument, standing in for a real container's own PSR-17 bindings.
 */
final class EchoContainer implements ContainerInterface
{
    private readonly Psr17Factory $psr17;

    /**
     * @param array<string, object> $services
     */
    public function __construct(
        private readonly array $services = [],
    ) {
        $this->psr17 = new Psr17Factory();
    }

    public function get(string $id): object
    {
        if (array_key_exists($id, $this->services)) {
            return $this->services[$id];
        }

        return match ($id) {
            NotFoundHandler::class => new NotFoundHandler($this->psr17),
            AllowedMethodsHandler::class => new AllowedMethodsHandler($this->psr17),
            HeadMiddleware::class => new HeadMiddleware($this->psr17),
            // @mago-expect analysis:unknown-class-instantiation
            default => class_exists($id) ? new $id() : new EchoHandler($id),
        };
    }

    public function has(string $id): bool
    {
        return true;
    }
}
