<?php

declare(strict_types=1);

// 100 routes: 10 resources x 10 routes.
/** @var Closure(int): array{routes: list<string>, requests: array<string, string>} $build */
$build = require __DIR__ . '/resources.php';

return $build(10);
