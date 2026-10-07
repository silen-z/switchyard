# Switchyard

A PSR-7/15 HTTP routing layer on top of [`silenz/beeline`](../beeline/README.md)'s compiled route
table: HTTP methods, groups, middleware, filters, request dispatch, URL generation and OpenAPI path
generation.

## HTTP routes

`Routes` is a higher-level declaration API with HTTP methods, groups and middleware. A `group()`
is itself a `Routes`, scoped by a prefix, its own middleware and its own tags; declaring runs
immediately, like any other PHP code. `->table($cacheKey)` gives the `RouteTable` `Router` takes, so
caching works as described in Beeline's [Caching](../beeline/README.md#caching) — only turning the
declared routes into the compiled matching structure is lazy and cache-gated, not declaring them:

```php
use SilenZ\Beeline\Cache\FileCache;
use SilenZ\Beeline\Router;
use SilenZ\Switchyard\Routes;

$routes = new Routes();
$routes->get('/', HomeController::class);
$routes->map(['GET', 'POST'], '/contact', ContactController::class);
$routes->any('/webhooks/{provider}', WebhookController::class);

$api = $routes->group('/api')->middleware('api');
$api->group()->middleware('guest')->post('/login', Login::class)->name('login');

$authed = $api->group()->middleware('auth');
$authed->get('/users/{id}', ShowUser::class)->name('users.show');
$authed->put('/users/{id}', UpdateUser::class);
$authed->group('/admin')->middleware('admin')->get('/stats', AdminStats::class)->middleware('audit');

$router = new Router(
    $routes->table('routes-' . APP_VERSION),
    cache: new FileCache(__DIR__ . '/var/cache'),
);
```

- **Verb helpers:** `get()`, `post()`, `put()`, `patch()`, `delete()` and `options()` declare a
  route for one method; `map()` for several; `any()` for every method; `redirect($path, $location,
  $status = 308)` a GET route answering with a `RedirectHandler` — see
  [Handling requests](#handling-requests) for why `$location`/`$status` are the one handler that
  needs no class name, container identifier or real instance at all: they're already plain data, so
  `HandlerBuilder` builds the `RedirectHandler` itself, fresh per request, straight from the route's
  own (fully cacheable) metadata.
- **The tree carries its own `MetadataRegistry`,** the store that keeps real instances out of the
  route cache (see below) — `new Routes()` creates one for you, shared by the root and every group,
  and carried automatically into `table()`'s result.
- **Route builder:** each call returns a `Route`, refined with `->name()`, `->middleware()`,
  `->tag()` and `->filter()`.
- **Groups:** `group()` takes an optional prefix and returns a nested `Routes`; `->middleware()`,
  `->tag()` and declaring routes on it may happen in any order, since accumulation only happens once
  the tree is resolved into definitions (`definitions()` or `table()`). Groups with no prefix only add
  middleware. Groups may share a prefix or nest freely, and a route only gets middleware from the
  groups it's declared in.
- **Middleware order:** enclosing groups' middleware first, outermost first, then the route's own.
  `/api/admin/stats` above gets `['api', 'auth', 'admin', 'audit']`.
- **Middleware on the root tree wraps every outcome,** not just its own routes — see
  [Handling requests](#handling-requests). It's baked into no route's metadata; `table()` keeps it as
  the table's own metadata instead (`['middleware' => [...]]`), cached with the routes.
- **`->notFound()`, called on the root only,** replaces `HandlerBuilder`'s built-in
  `NotFoundHandler` (see [Handling requests](#handling-requests)) — declared here rather than
  passed to `build()`, so whoever declares the routes may configure it even when that isn't whoever
  builds the `HandlerBuilder`, e.g. a framework exposing `Routes` to its own users while keeping
  `HandlerBuilder` to itself. Unlike `->middleware()`, a later call replaces the earlier one rather
  than accumulating — there's one not-found handler for the whole table, never one per group, so
  calling it on a `group()` throws an `InvalidRouteException` instead of silently reconfiguring the
  root from somewhere that looks scoped:

  ```php
  $routes->notFound(new MyNotFoundPage($twig));
  ```

  There's no equivalent for the default error middleware — see `->middleware()` above and
  [Handling requests](#handling-requests) for why one isn't needed.
- **Tags:** `->tag('public', ...)` on a route or a group labels routes for your own code. Group
  tags are inherited, outermost first, without duplicates. The router never interprets tags.
- **Definitions as a class:** nothing stops you from grouping declarations into an invokable class
  and calling it yourself, e.g. `(new AppRoutes())($routes)`. You aren't limited to `Routes`
  either — `new RouteTable($callable, $key)` takes any callable returning `RouteDefinition`s directly
  (see Beeline's [Caching](../beeline/README.md#caching)).
- **A handler, middleware entry or filter may be a real instance or closure,** not just a class name:
  kept exactly as given, not restricted to cacheable plain data the way a bare `RouteTable`'s
  metadata is. A route's whole metadata — handler, middleware, filters and all — is handed to the
  tree's `MetadataRegistry` as one unit, and only *that unit's id* is what actually gets compiled
  into the cache, so routes can still be cached without giving up configured instances:

  ```php
  $routes->get('/reports', new ReportController($reportRepository));
  $routes->group('/api')->middleware(new RateLimiter($rateLimiterConfig))->get(...);
  ```

  Unlike the compiled routes, the registry is never cached — it's rebuilt fresh every time `$routes`
  is declared, so a unit can only ever be looked up in the registry of that same declaration, never a
  different one's. That's never something you have to get right by hand: it travels with `table()`'s
  `RouteTable`, so a `Router` built from it always carries the matching registry, and resolves every
  id back to its real metadata before `match()` ever returns — see [Handling requests](#handling-requests).
- **`LazyRoutes` declares lazily instead,** for when declaring on every request costs too much.
  `$define` gets a fresh tree and only runs when the route cache has no entry, so a request answered
  from the cache declares nothing at all:

  ```php
  use SilenZ\Switchyard\LazyRoutes;

  $table = LazyRoutes::table(static function (LazyRoutes $routes): void {
      $routes->middleware(CorsMiddleware::class);
      $routes->get('/', HomeController::class);
      $routes->group('/api')->middleware('api')->get('/users/{id}', ShowUser::class);
  }, 'routes-' . APP_VERSION);

  $router = new Router($table, new FileCache(__DIR__ . '/var/cache'));
  ```

  Full runnable apps contrasting the two, each with its own `index.php` to run with
  `php -S localhost:8000 index.php`, are in
  [`examples/eager-routes`](examples/eager-routes/index.php) and
  [`examples/lazy-routes`](examples/lazy-routes/index.php).

  The price: every handler, middleware entry and filter must be a class name or container identifier
  — an instance or closure would only exist on the request that built the cache, so `$define` throws
  an `InvalidRouteException` the moment it declares one. In exchange, nothing it declares ever needs
  a registry at all, so an all-lazy router carries none — `RouteTable`'s own default (`null`),
  nothing `LazyRoutes` has to set up.

### Filters: methods and your own conditions

A route's HTTP methods are stored with it directly, not as a filter (`any()` routes get none).
`->filter(MyFilter::class)` adds a condition of your own on top.

There's deliberately no built-in parameter validation such as regex constraints: check parameter
values in the controller. If a route really must be skipped for some values, so that another
route can take the request, write a filter for it.

`HandlerBuilder` (see [Handling requests](#handling-requests)) is how you match: it checks
the route's methods with `MethodNotAllowed` — generic, container-free — and resolves and runs
its filters, the only place that knows about the container.

- **A rejected route doesn't exist for that request.** Matching continues, so a request falls
  through to another route: `GET /users/new` skips `POST /users/new` and reaches
  `GET /users/{id}`.
- **A 405's allowed methods count only routes rejected solely because of their method.** A route
  whose own filter fails, such as a feature switch, doesn't make a 405.
- **A custom filter implements `RouteFilter`:** one method,
  `accepts(RouteMatch $match, ServerRequestInterface $request): bool`. There's no separate
  configuration parameter — a filter that needs configuration takes it as a constructor argument
  instead, e.g. `new FeatureRouteFilter('beta')`. Anything request-specific the filter needs goes into
  the request's PSR-7 attributes (`$request->getAttribute(...)`), loaded once before matching;
  `$match->params` gives the candidate's URL-decoded parameters when what decides a route exists is
  baked into the path itself, e.g. a version or tenant segment.
- **Filters are resolved per match, not stored statically.** `$container->get($filterClass)`, the
  same way as middleware and handlers, so a filter with constructor dependencies is wired up once per
  match like any other service. `->filter(new MyFilter($dependency))` skips the container entirely by
  giving a ready instance instead of a class name. A class name is therefore only right for a filter
  that behaves the same everywhere, or varies by request rather than by route; one filter class that
  needs different configuration per route, like a feature name, needs a separate instance per route
  (`new FeatureRouteFilter('beta')`, `new FeatureRouteFilter('bulk-edit')`) — a container resolving a
  shared class name has no way to tell routes apart. Or a container identifier per configuration
  instead, e.g. `->filter('feature.beta')` with the container resolving `'feature.beta'` to
  `new FeatureRouteFilter('beta')`: that's how routes declared lazily, which can't take instances,
  vary a filter per route. Any string that isn't an existing class name is taken as a container
  identifier; one that resolves to something other than a `RouteFilter` throws once it's matched.
- **Filters decide whether a route applies, never who is asking.** Authentication and permissions
  belong to middleware, which runs after matching.

Each route's metadata, as returned in `RouteMatch::$route`:

```php
[
    'handler' => ShowUser::class,
    'middleware' => ['api', 'auth'],
    'name' => 'users.show',                      // only when named
    'path' => '/api/users/{id}',                 // only when named, for URL generation
    'tags' => ['public'],                        // only when tagged
    'methods' => ['GET'],                        // only for routes with methods (not any())
    'filters' => [FeatureRouteFilter::class],   // only when there are any, checked in this order
]
```

`handler`, each `middleware` entry and each `filters` entry is exactly what you declared it as — a
class name, a container identifier, or a real instance or closure — resolved already by the time
`RouteMatch::$route` reaches you, even when it came from a compiled cache (see
[HTTP routes](#http-routes) for how a `MetadataRegistry` makes that safe).

Tags let cross-cutting code act on routes without splitting them into more groups. For example, one
auth middleware for the whole site that lets public routes through:

```php
$public = $r->group()->middleware(AuthMiddleware::class);
$public->get('/login', LoginForm::class)->tag('public');
$public->get('/account', ShowAccount::class);

// in AuthMiddleware::process(), which HandlerBuilder runs inside each route's stack:
$found = Found::fromRequest($request);
if (!in_array('public', $found?->tags ?? [], true) && !$session->isLoggedIn()) {
    return new Response(401);
}
```

`Found` is only on the request inside the route's stack (see
[Handling requests](#handling-requests)), so this works as route or group middleware, not as
middleware that runs before `HandlerBuilder`.

### Handling requests

`HandlerBuilder` answers requests from an already-built `Router`. `build()` returns the
PSR-15 handler for one request — the matched route's middleware and handler as one stack built with
[Relay](https://relayphp.com/), or a 404/405/redirect handler — so you don't handle `RouteMatch`,
`NoMatch`, methods and filters yourself:

```php
use SilenZ\Beeline\Router;
use SilenZ\Switchyard\HandlerBuilder;
use SilenZ\Switchyard\Routes;

$routes = new Routes();
$routes->get('/', HomeController::class);
$routes->group('/api')->middleware('api')->get('/users/{id}', ShowUser::class);

// $container resolves filters, middleware, handlers and the builder's own fallback handlers
$router = new Router($routes->table());
$builder = new HandlerBuilder($container, $router);

$response = $builder->build($request)->handle($request);
```

- **`$request` is a PSR-7 `ServerRequestInterface`.** The path comes from
  `$request->getUri()->getPath()`.
- **`$container` is a PSR-11 `ContainerInterface`.** It resolves everything a stack entry is named as:
  the route's middleware and handler, its filters, and `Routes::notFound()` if it named a class or
  container identifier instead of giving an instance. So it needs a PSR-17 response factory for
  `NotFoundHandler` and `AllowedMethodsHandler` (unless the first is replaced), and a stream
  factory for `HeadMiddleware` and `ErrorMiddleware` (which needs both — always, since
  there's no way to replace it); a container that autowires constructor arguments needs no
  registration of its own. `Psr\Http\Message\ResponseFactoryInterface` itself must also resolve on its
  own — `HandlerBuilder` asks for it directly to build its own trailing-slash `RedirectHandler`,
  rather than resolving a handler class by name for it. Each middleware entry must resolve to a
  `Psr\Http\Server\MiddlewareInterface`, and the handler to a `Psr\Http\Server\RequestHandlerInterface`.
- **`$router` is already built** — bring your own, shared across requests so its compiled routes are
  only loaded from the cache (or compiled) once (see Beeline's [Caching](../beeline/README.md#caching));
  `HandlerBuilder` doesn't build or memoize one itself. A handler, middleware entry or filter declared
  as a real instance or closure already comes back resolved in `$router->match()`'s result —
  `HandlerBuilder` never touches a `MetadataRegistry` itself, so there's no separate registry argument
  here to keep in sync with `$router`; see [HTTP routes](#http-routes).
- **A handler, middleware entry or filter is never specially resolved by this library beyond what's
  described above** — a class name or container identifier through the container, anything else kept
  exactly as given — so it reaches Relay as declared, and only Relay's own rules decide what happens
  with it: a `Psr\Http\Server\MiddlewareInterface` or `RequestHandlerInterface` instance, or any
  callable. A `[$instance, 'method']` pair (`Routes` only, since `LazyRoutes` can't hold an instance)
  works for exactly this reason — PHP treats it as an ordinary callable — not because of anything
  Switchyard does with it. If you want dispatch by class name and method name, resolve it yourself
  before declaring the route, or build a small invokable handler of your own; that's deliberately a
  concern for whatever framework or application sits on top of this library, not this one.
- **The match is a request attribute.** PSR-15 handlers take only the request, so
  `Found::fromRequest($request)` gives the route's own middleware and handler a
  `Found` (null outside a matched route's stack): its `params` (URL-decoded, by name), `name` and `tags`. Parameters are deliberately
  not separate attributes, so they can't collide with the application's own:

  ```php
  $id = Found::fromRequest($request)?->params['id'];
  ```

  Middleware can use it too, for example to skip authentication on routes tagged `public`, without
  matching again.
- **Requests no route takes get one of four answers:**

  | Case | Answer | To change it |
  | --- | --- | --- |
  | No route for the path, but its trailing-slash counterpart answers | `RedirectHandler`: 308 + `Location` | — |
  | No route for the path at all | `NotFoundHandler`: 404 | `Routes::notFound()` |
  | Routes for the path, not the method | `AllowedMethodsHandler`: 405 + `Allow` | — |
  | The same, for an OPTIONS request | `AllowedMethodsHandler`: 200 + `Allow` | — |

  For the middle two, middleware sees `MethodNotAllowed::fromRequest($request)`, whose
  `allowed` lists the path's methods, e.g. `['GET', 'PUT', 'HEAD']` — HEAD is included whenever GET
  is.
- **"/foo" and "/foo/" redirect to each other when only one is declared.** Declare both yourself
  (they're always distinct routes, never aliases — see [HTTP routes](#http-routes)) and neither
  redirects; declare only one and a request for the other gets a 308 to it, query string included,
  but only when it would actually be answered there — a HEAD request redirects if the declared route
  answers GET, but a method the target genuinely doesn't accept gets a plain 404 rather than a
  redirect to a 405. `RedirectHandler` isn't specific to this: it's a plain PSR-15 handler, so a
  route may use one directly for a moved path, e.g.
  `$routes->get('/old', new RedirectHandler($psr17, '/new'))` — or, more conveniently,
  `$routes->redirect('/old', '/new')` declares exactly that GET route for you (see
  [HTTP routes](#http-routes)), 308 by default.
- **An uncaught throw becomes a plain-text 500.** `ErrorMiddleware` wraps everything else —
  the matched route's own stack, the four answers above, and the root's own middleware (see
  [HTTP routes](#http-routes)) — so a route handler, filter, or any middleware that throws answers
  with a `Server error` body instead of the exception reaching `build()`'s caller. There's no
  parameter or setter to replace it with something else: it's always the outermost entry. Want
  different behavior — logging, a formatted page? Add your own error-catching middleware with
  `Routes::middleware()` instead, declared first:

  ```php
  $routes->middleware(new LoggingErrorMiddleware($logger));
  ```

  An exception unwinds from the innermost catch outward, so yours — closer to the handler than the
  built-in default — answers it first; the default further out never sees a throwable at all. Several
  error-catching middlewares stacked this way (yours, a framework's, Switchyard's own) cost nothing but
  one unused `try`/`catch` per layer that never fires, not a conflict.
- **HEAD matches GET routes automatically.** A route declared for HEAD itself still wins.
- **A HEAD response never has a body,** whoever answers — a GET route, a HEAD or `any()` route, the
  not-found and method-not-allowed handlers, or `ErrorMiddleware`'s own 500: it's dropped, keeping
  status and headers, as RFC 9110 requires. The request is never rewritten: filters, middleware and
  the handler all see HEAD, so a handler can skip building a body it won't send. A handler for
  several methods should therefore branch on the method that changes things —
  `if ($method === 'POST')`, not `if ($method === 'GET') ... else` — or a HEAD request takes the
  POST path.
- **OPTIONS is answered automatically.** A route declared for OPTIONS, or with `any()`, takes the
  request; otherwise the OPTIONS handler does, where other methods would get a 405. Filters run
  against the OPTIONS request itself.
- **Request attributes for custom filters:** PSR-7's own `$request->withAttribute($name, $value)`,
  read back by the filter with `$request->getAttribute($name)`.
- **All middleware is declared on the routes, not on the builder.** Group middleware
  (`$r->group('/api')->middleware(...)`) only runs for the routes in that group — there's no "wrong
  method" or "no route" response to decorate for a path the group doesn't own. Middleware on the root
  `Routes` (`$routes` above) is different: it also wraps the not-found,
  method-not-allowed and OPTIONS answers, so it's the way to run something for every outcome, matched
  route or not — e.g. CORS, which must still decorate a 404 or a preflight to a path with no route:

  ```php
  $routes->middleware(new Cors(['https://app.example']));
  ```

  ```php
  final class Cors implements MiddlewareInterface
  {
      public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
      {
          $response = $handler->handle($request);
          if (!$request->hasHeader('Origin')) {
              return $response;
          }

          $response = $response->withHeader('Access-Control-Allow-Origin', $request->getHeaderLine('Origin'));
          if ($request->getMethod() === 'OPTIONS' && $response->hasHeader('Allow')) {
              // Built by AllowedMethodsHandler, in exactly the format this header wants.
              $response = $response->withHeader('Access-Control-Allow-Methods', $response->getHeaderLine('Allow'));
          }

          return $response;
      }
  }
  ```
- **Middleware that must run before matching** — anything that changes the request, or sets the
  attributes filters read — goes in a stack around the builder instead; its last entry is a one-liner,
  `return $builder->build($request)->handle($request);`. Unlike `$routes->middleware()`, this runs
  before the route is even looked up, so it can affect matching itself.

### URL generation

`UrlGenerator` builds URLs for named routes:

```php
use SilenZ\Switchyard\UrlGenerator;

$urls = new UrlGenerator($router);
$urls->url('users.show', ['id' => 42]);                // "/api/users/42"
$urls->url('users.show', ['id' => 42, 'tab' => 'x']);  // "/api/users/42?tab=x" (extra params become the query)
$urls->url('users.show', []);                          // throws: missing parameter "id"
```

- **Every placeholder is required.** Values are `rawurlencode`d, so the generated URL matches its
  route again with the same parameters. A catch-all keeps its slashes:
  `['path' => 'docs/a b.pdf']` gives `/files/docs/a%20b.pdf`.
- **Empty values:** `{id}` and `{path+}` can't be empty. An empty `{path*}` drops its slash:
  `/assets`, not `/assets/`.
- **Values** may be strings, ints or `Stringable`s. Query values are anything `http_build_query()`
  takes.
- **Errors** throw `Exception\UrlGenerationException`: an unknown name, or a missing, empty or
  unsupported parameter.
- **Works from the cache.** Named routes keep their path in the metadata, so URLs can be generated
  without declaring the routes. The index of names is built on the first `url()` call.

Compile-time errors include duplicate route names, empty names or tags, invalid prefixes or
methods, and filter classes that don't implement `RouteFilter`.

### Declared routes, and generating OpenAPI

`$router->table()->definitions()` returns the routes as declared — full paths and metadata,
uncompiled, never read from or written to the cache. It's for tooling that needs the declarations
themselves, not for matching requests:

```php
foreach ($router->table()->definitions() as $definition) {
    $definition->path;         // "/api/users/{id}"
    $definition->metadata;     // ['handler' => ..., 'name' => 'users.show', 'methods' => ['GET'], ...]
    $definition->pathTemplate; // "/api/users/{id}" — every parameter as "{name}", catch-alls included
    $definition->parameters;   // [new PathParameter('id', required: true)], in path order
}
```

`pathTemplate` and `parameters` are what `OpenApi\PathsGenerator` (below) builds its own path
parameters from; use them directly for any other tooling that needs a route's parameters without
re-deriving them from `path`.

`OpenApi\PathsGenerator` builds the `PathItem`s of an OpenAPI document from exactly that, as
[zircote/swagger-php](https://github.com/zircote/swagger-php) `OpenApi\Attributes` objects — the same
types that package's own attributes use, just built by hand instead of scanned from docblocks.
zircote/swagger-php is a regular (not dev-only) dependency, so it's always installed alongside
Switchyard, whether or not you use `PathsGenerator`:

```php
use OpenApi\Attributes as OA;
use SilenZ\Switchyard\OpenApi\PathsGenerator;

$document = new OA\OpenApi(
    openapi: '3.1.0',
    info: new OA\Info(title: 'Example API', version: '1.0.0'),
    // The registry is only needed when the routes came from Routes (or anything else with a
    // MetadataRegistry) — null for LazyRoutes, whose metadata is already the real thing.
    paths: PathsGenerator::generate($router->table()->definitions(), $router->table()->registry()),
);

$document->toJson(); // or ->toYaml(), ->saveAs(...), ->validate()
```

A full runnable version is in [`examples/openapi.php`](examples/openapi.php) — run it with
`php examples/openapi.php`.

- **It covers only what Beeline's route table knows:** paths, methods, path parameters, names (as
  `operationId`) and tags. Request/response bodies, security schemes, `info` and `servers` aren't
  its business — merge them into the document yourself, using the same `OA\*` types, keyed off
  `operationId` or route name.
- **Every operation gets a placeholder `200` response**, since `responses` is a required field of an
  OpenAPI operation and neither Beeline nor Switchyard has any notion of what a route responds with.
  Replace it yourself.
- **Catch-alls become a single `{name}` path parameter.** OpenAPI has no "rest of the path"
  placeholder, so a `{name*}`/`{name+}`'s actual multi-segment behavior isn't represented.
- **`any()` routes list every HTTP method OpenAPI supports**, since no methods were declared to
  narrow it down.
- **No scanner involved.** `OA\*` objects are self-contained — `PathsGenerator` builds them directly
  and `OA\OpenApi` serializes itself with `->toJson()`/`->toYaml()`/`->validate()`; nothing scans
  attributes off real classes. zircote/swagger-php's own attribute-scanning generator isn't used here.

## Development

There's no PHP on the host by assumption — use the Docker setup in `docker/`. Since this package
depends on `silenz/beeline` via a local path repository (`../beeline`), `docker-compose.yml` mounts
the *parent* directory, not just this repo, so the sibling is visible inside the container:

```bash
docker compose up -d
docker compose exec php composer install
docker compose exec php composer qa
```

`composer qa` runs `mago format --check`, `mago lint`, `mago analyze` and then the tests
(`composer test`). If Docker isn't available, any PHP ≥8.4 CLI binary works the same way, as long as
`../beeline` exists on disk.

```bash
composer bench
```

The one benchmark here, `DeclareBench`, measures the cost of declaring routes through `Routes`
(`benchFlat`) versus declaring and compiling them (`benchCompiled`) — see its own docblock for how
that compares to Beeline's own benchmarks (in the sibling `beeline` repo) for the compile-only cost.
Run it with `vendor/bin/phpbench run --report=aggregate`.
