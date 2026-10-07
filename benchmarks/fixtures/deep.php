<?php

declare(strict_types=1);

// 20 routes, 12 segments deep, alternating static and parameter segments.
$routes = [];
for ($i = 0; $i < 20; $i++) {
    $routes[] = '/area' . $i . '/a/{p1}/b/{p2}/c/{p3}/d/{p4}/e/{p5}/end';
}

return [
    'routes' => $routes,
    'requests' => [
        'param' => '/area10/a/1/b/2/c/3/d/4/e/5/end',
        'not-found' => '/area10/a/1/b/2/c/3/d/4/e/5/nope',
    ],
];
