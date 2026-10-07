<?php

declare(strict_types=1);

/**
 * Builds a fixture of $count REST-like resources with 10 routes each. Requests target the middle
 * resource, so neither router benefits from it being first or last.
 *
 * @return array{routes: list<string>, requests: array<string, string>}
 */
return static function (int $count): array {
    $routes = [];
    for ($i = 0; $i < $count; $i++) {
        $resource = '/resource' . $i;
        $routes[] = $resource;
        $routes[] = $resource . '/new';
        $routes[] = $resource . '/new/draft';
        $routes[] = $resource . '/{id}';
        $routes[] = $resource . '/{id}/edit';
        $routes[] = $resource . '/{id}/history';
        $routes[] = $resource . '/{id}/comments';
        $routes[] = $resource . '/{id}/comments/{comment}';
        $routes[] = $resource . '/search/{query}';
        $routes[] = $resource . '/files/{path+}';
    }

    $middle = '/resource' . intdiv($count, num2: 2);

    return [
        'routes' => $routes,
        'requests' => [
            'static' => $middle . '/new/draft',
            'param' => $middle . '/123/comments/456',
            'catch-all' => $middle . '/files/2024/report.pdf',
            'not-found' => '/missing/123',
            'backtrack-hit' => $middle . '/new/edit',
            'backtrack-miss' => $middle . '/new/unknown',
        ],
    ];
};
