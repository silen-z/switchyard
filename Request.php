<?php

declare(strict_types=1);

namespace SilenZ\Segmatch\Http;

use function strtoupper;

/**
 * What guards know about the request being matched.
 *
 * `$attributes` carries anything an application's own guards need, loaded once before matching,
 * e.g. `['features' => ['beta' => true]]` for a feature-switch guard.
 */
final readonly class Request
{
    public string $method;

    /**
     * @param string $method HTTP method, case-insensitive
     * @param array<string, mixed> $attributes
     */
    public function __construct(
        string $method,
        public array $attributes = [],
    ) {
        $this->method = strtoupper($method);
    }
}
