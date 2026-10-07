<?php

/**
 * Generates an OpenAPI document from a route declaration, using `OpenApi\PathsGenerator` for the
 * `paths` and segmatch/zircote-swagger-php types for everything else.
 *
 * Run from the repository root:
 *
 *     php examples/openapi.php
 */

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use OpenApi\Attributes as OA;
use SilenZ\Segmatch\Http\Routes;
use SilenZ\Segmatch\OpenApi\PathsGenerator;
use SilenZ\Segmatch\Router;

$routes = new Routes();
$routes->get('/', 'home')->name('home')->tag('public');

$users = $routes->group('/api/users')->tag('api', 'users');
$users->get('', 'users.list')->name('users.list');
$users->post('', 'users.create')->name('users.create');
$users->get('/{id}', 'users.show')->name('users.show');
$users->map(['PUT', 'PATCH'], '/{id}', 'users.update')->name('users.update');

$routes->get('/assets/{path*}', 'assets')->name('assets');

$router = new Router($routes->table());

// PathsGenerator::generate() returns a list<OA\PathItem> built from the declared routes — everything
// else in the document (info, servers, security, request/response bodies) is yours to add, using the
// same OA\* types.
$document = new OA\OpenApi(
    openapi: '3.1.0',
    info: new OA\Info(title: 'Example API', version: '1.0.0'),
    paths: PathsGenerator::generate($router->table()->definitions()),
);

if (!$document->validate()) {
    $message = "Generated document failed OpenAPI validation.\n";
    fwrite(STDERR, $message);
    exit(1);
}

echo $document->toJson(), "\n";
