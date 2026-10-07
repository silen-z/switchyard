<?php

declare(strict_types=1);

// 1000 static-heavy routes: 500 siblings under the root, each with one child.
$routes = [];
for ($i = 0; $i < 500; $i++) {
    $routes[] = '/section' . $i;
    $routes[] = '/section' . $i . '/page';
}

return [
    'routes' => $routes,
    'requests' => [
        'static' => '/section250/page',
        'not-found' => '/section250/other',
    ],
];
