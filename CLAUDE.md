# Switchyard

PSR-7/15 HTTP routing layer on top of Beeline's compiled route table. Beeline
(`silenz/beeline`) is consumed through a composer path repository at
`../beeline`, so it must be checked out as a sibling directory.

## Commands

- `composer install`
- `vendor/bin/phpunit`
- `composer bench` (PHPBench, config in `phpbench.json`)

## Router benchmarking

`benchmarks/CachedMatchBench.php` — warm-cache, per-request cost, four-way:
`benchRoutes` / `benchLazyRoutes` / `benchSymfonyRouter` (Symfony's actual
`Router`, `ConfigCache` + a `ClosureLoader`) / `benchFastRoute`
(`cachedDispatcher`), over every `Fixtures::NAMES` fixture. Each rev is one
simulated request: build the router from an already-written cache, match one
path, rotating through `Fixtures::paths()`. `setUp()` wipes and rewrites all
caches (in gitignored `var/cached-match-bench/`). LazyRoutes, FastRoute and
`benchSymfonyRouter` are benchmarked through a loader/`$define` that throws,
so a cache miss fails loudly. Catch-alls are translated for Symfony (`{name}`
+ `.+`/`.*` requirement) and FastRoute (`{name:.+}`, `[/{name:.*}]`), and both
get routes registered static → param → catch-all to match Beeline precedence.
Run one fixture: `vendor/bin/phpbench run benchmarks/CachedMatchBench.php
--report=aggregate --variant=medium`. Numbers are only meaningful with OPcache
enabled for CLI (`opcache.enable_cli=1`), which is off by default.

**Why there's no bare `CompiledUrlMatcher` subject here**: a hand-built
`CompiledUrlMatcher` skips everything Symfony's real `Router` class does
around it (`ConfigCache`'s freshness check, the `ClosureLoader` indirection),
so it's not a fair comparison for `Routes`/`LazyRoutes` — which always go
through Beeline's own `Router`. That bare-matcher comparison lives in
beeline's own benchmarks instead (`CompileBench`/`LoadBench`/`LoadMatchBench`/
`MatchBench`/`MixedMatchBench`'s own `benchSymfony`), alongside Beeline's bare
`Matcher`, the class it's actually fair to compare against. On `medium`,
`benchSymfonyRouter` here (~5.4–5.7μs) is measurably *slower* than
`benchLazyRoutes` (~5.0–5.2μs) — the opposite of what comparing against a bare
`CompiledUrlMatcher` (~3.0–3.2μs) would suggest, which is exactly why that
comparison doesn't belong in this file.

**OPcache gotcha (critical)**: `opcache.file_update_protection` (default 2)
won't cache files whose mtime is within 2s of the *request start* — in CLI,
the process start. Cache files written in `setUp()` are therefore never
OPcached and get recompiled on every `require` (~1000x slower than an OPcache
hit), swamping every router's real cost. `phpbench.json` sets
`runner.php_config: {opcache.file_update_protection: 0}`; any ad-hoc script
that writes then requires caches in-process needs `-d
opcache.file_update_protection=0` too.

**JIT gotcha**: PHPBench runs every iteration in a fresh process, and CLI
OPcache/JIT state dies with it, so without a long warmup the timed revs also
pay for the tracing JIT compiling the hot code — that inflated results 2–4x
and distorted the ranking. Hence `#[Warmup(1000)]`: results
plateau from ~500 warmup revs on medium and ~1000 on large. Keep any new
steady-state benchmark warmed the same way.

**Why `Routes` is far slower than `LazyRoutes` on a warm cache**: a
registry-backed table (`Routes::table()`) always re-runs its `collect()`
closure, and the `RouteDefinition` constructor eagerly runs `parse`
(+`template`, `parameters`) for every route — work that's thrown away on a
cache hit and dominates the per-request cost, well above declaring the routes
themselves.
