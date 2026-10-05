<?php

declare(strict_types=1);

namespace SilenZ\Segmatch\Http;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use SilenZ\Segmatch\Exception\InvalidRouteException;
use UnexpectedValueException;

use function array_is_list;
use function class_exists;
use function count;
use function get_debug_type;
use function is_array;
use function is_int;
use function is_object;
use function is_string;
use function method_exists;
use function sprintf;

/**
 * A route handler given as `[target, 'method']`, e.g. `[UserController::class, 'show']`, as a PSR-15
 * handler: the target — a class name or container identifier, resolved from the container, or an
 * instance — is only resolved once the request actually reaches it, past the route's middleware, and
 * then `$target->method($request)` answers it. The route's parameters are on the request, as
 * `$request->getAttribute(Found::class)->params`, like for any other handler.
 *
 * @internal built by {@see Dispatcher} for an array handler
 */
final readonly class MethodHandler implements RequestHandlerInterface
{
    public function __construct(
        private Resolver $resolver,
        private object|string $target,
        private string $method,
    ) {}

    /**
     * A route's handler entry as the Relay queue should get it: a `[target, 'method']` pair — given
     * as is, or as a {@see Registry} id standing in for one holding an instance — as a `MethodHandler`,
     * anything else unchanged for Relay to resolve.
     */
    public static function wrap(Resolver $resolver, mixed $handler): mixed
    {
        // A Registry id is resolved right away, which is cheap: it's an array lookup, not the container.
        // @mago-expect analysis:mixed-assignment
        $pair = is_int($handler) ? $resolver->entry($handler) : $handler;

        return self::isPair($pair) ? new self($resolver, $pair[0], $pair[1]) : $handler;
    }

    /**
     * Checks an array handler while its route is declared, rather than on the first request that
     * reaches it: it must be a `[target, 'method']` pair, and when the target is an object or the
     * name of an existing class, the method must exist on it. A container identifier's method can only
     * be checked once the container resolves it.
     *
     * @internal for {@see Route}
     *
     * @throws InvalidRouteException
     */
    public static function check(mixed $handler, string $owner): void
    {
        if (!is_array($handler)) {
            return;
        }

        if (!self::isPair($handler)) {
            throw new InvalidRouteException(sprintf(
                "%s given as an array must be [class name, container identifier or object, 'method'].",
                $owner,
            ));
        }

        [$target, $method] = $handler;
        if ((is_object($target) || class_exists($target)) && !method_exists($target, $method)) {
            throw new InvalidRouteException(sprintf(
                '%s calls %s::%s(), which does not exist.',
                $owner,
                is_object($target) ? $target::class : $target,
                $method,
            ));
        }
    }

    /**
     * Whether $handler is a `[target, 'method']` pair: a class name, container identifier or object,
     * and a method name.
     *
     * @psalm-assert-if-true array{0: object|string, 1: string} $handler
     */
    public static function isPair(mixed $handler): bool
    {
        return (
            is_array($handler)
            && array_is_list($handler)
            && count($handler) === 2
            && (is_string($handler[0]) || is_object($handler[0]))
            && is_string($handler[1])
        );
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        // @mago-expect analysis:mixed-assignment
        $target = is_string($this->target) ? $this->resolver->entry($this->target) : $this->target;
        if (!is_object($target)) {
            throw new UnexpectedValueException(sprintf(
                'Handler target "%s" resolved to %s, not an object to call %s() on.',
                is_string($this->target) ? $this->target : get_debug_type($this->target),
                get_debug_type($target),
                $this->method,
            ));
        }

        // Calling a method named by the route is what this class is for.
        // @mago-expect analysis:mixed-assignment
        // @mago-expect analysis:string-member-selector
        $response = $target->{$this->method}($request);
        if (!$response instanceof ResponseInterface) {
            throw new UnexpectedValueException(sprintf(
                'Handler %s::%s() returned %s instead of a %s.',
                $target::class,
                $this->method,
                get_debug_type($response),
                ResponseInterface::class,
            ));
        }

        return $response;
    }
}
