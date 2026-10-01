<?php

declare(strict_types=1);

namespace SilenZ\Segmatch\Http;

use function is_array;
use function is_string;
use function preg_match;

/**
 * Accepts a route only when its parameters match regular expressions, as declared with
 * {@see Route::where()}; the configuration maps parameter names to patterns.
 */
final class PatternGuard implements Guard
{
    public static function accepts(mixed $config, Request $request, array $params): bool
    {
        if (!is_array($config)) {
            return false;
        }

        // @mago-expect analysis:mixed-assignment
        foreach ($config as $parameter => $pattern) {
            $value = $params[(string) $parameter] ?? null;
            if ($value === null || !is_string($pattern) || preg_match(Route::regex($pattern), $value) !== 1) {
                return false;
            }
        }

        return true;
    }
}
