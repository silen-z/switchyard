<?php

declare(strict_types=1);

namespace SilenZ\Segmatch\Http;

/**
 * Result of {@see Dispatcher::match()}: routes exist for the path, but not for the request's
 * HTTP method (405).
 */
final readonly class MethodNotAllowed
{
    /**
     * @param non-empty-list<string> $allowed the methods the path supports, for the `Allow` header
     */
    public function __construct(
        public array $allowed,
    ) {}
}
