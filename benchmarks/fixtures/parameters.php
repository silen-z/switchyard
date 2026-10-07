<?php

declare(strict_types=1);

// 50 parameter-heavy routes: one static segment followed by five parameters.
$routes = [];
for ($i = 0; $i < 50; $i++) {
    $routes[] = '/p' . $i . '/{a}/{b}/{c}/{d}/{e}';
}

return [
    'routes' => $routes,
    'requests' => [
        'param' => '/p25/1/2/3/4/5',
        'not-found' => '/p25/1/2/3/4',
    ],
];
