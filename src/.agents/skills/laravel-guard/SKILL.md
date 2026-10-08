---
name: laravel-guard
description: Use when an agent works in rennf93/laravel-guard or touches its Laravel adapter surface: Laravel middleware adapter that wires Illuminate HTTP requests to the guard-core-php engine and contains no security logic of its own; covers middleware construction and configuration (GuardEngine, redisFailOpen), Illuminate request adaptation (LaravelGuardRequest, bounded 256 KiB body read, $request->ip() client host), block-response translation (ResponseTranslator, fail-closed 500), composer scripts (composer lint, composer test), and the plain-PHP test runner bin/test_laravel.php with its REDIS_HOST integration mode.
---

# laravel-guard

## Quick Reference

- Package `rennf93/laravel-guard`; PSR-4 namespace `RenzoFranceschini\GuardCoreLaravel\` mapped to `src/`.
- Three final classes: `GuardMiddleware` (Laravel middleware), `LaravelGuardRequest` (implements the core `GuardRequest` contract), `ResponseTranslator` (`GuardResponse` to `Illuminate\Http\Response`).
- Requires PHP `^8.2`, `rennf93/guard-core-php ^0.1.0` (locked v0.1.0), `illuminate/http ^10.0|^11.0|^12.0`, and `symfony/http-foundation ^6.4|^7.0` (the response base class the middleware returns).
- Commands: `composer lint` (php -l sweep over `src` and `bin`), `composer test` (`php bin/test_laravel.php`). CI also runs `composer install --no-interaction --no-progress`, `composer update rennf93/guard-core-php --no-interaction`, the same php -l sweep, `REDIS_HOST=127.0.0.1 php bin/test_laravel.php`, and `composer audit`.
- The adapter holds no security logic. Every verdict comes from `GuardEngine::execute()`.

## Installation

```bash
composer require rennf93/laravel-guard
```

Until `rennf93/guard-core-php` has a Packagist release, point Composer at its repository (README setup):

```json
{
    "minimum-stability": "dev",
    "prefer-stable": true,
    "repositories": [
        { "type": "vcs", "url": "https://github.com/Guard-Core/guard-core-php" }
    ]
}
```

The dependency constraint is `rennf93/guard-core-php: ^0.1.0`. This package's own composer.json resolves the core locally through a `../guard-core-php` path repository (marked `"canonical": false`) with the VCS URL above as fallback.

## Setup

```php
use RenzoFranceschini\GuardCore\Config\SecurityConfig;
use RenzoFranceschini\GuardCore\Engine\GuardEngine;
use RenzoFranceschini\GuardCoreLaravel\GuardMiddleware;

$config = new SecurityConfig(
    enableRedis: false,
    blacklist: ['192.0.2.0/24'],
    rateLimit: 100,
    rateLimitWindow: 60,
    enableRateLimiting: true,
);

$engine = new GuardEngine($config);
$guard = new GuardMiddleware($engine);

// Laravel 11/12, bootstrap/app.php
->withMiddleware(function (Middleware $middleware) {
    $middleware->prepend(GuardMiddleware::class);
});
```

- The consumer builds the `SecurityConfig` and the `GuardEngine` (typically in a service provider binding). This package ships no publishable config file and no service provider of its own.
- Lifecycle: construct `GuardEngine` (and therefore `GuardMiddleware`) per request in classic FPM, or per worker under Octane/FrankenPHP. The middleware holds no mutable state of its own.
- Redis: set `enableRedis: true` and point `REDIS_HOST`/`REDIS_PORT` at your instance for distributed rate limits, IP bans, and cloud-range caches. With `redisFailOpen: true` the middleware still constructs and serves requests when Redis is unreachable; with `redisFailOpen: false` construction fails closed.

## GuardMiddleware

- `final class GuardMiddleware`; a Laravel middleware (`handle(Request $request, Closure $next)`), not PSR-15.
- `__construct(GuardEngine $engine)`. It builds a `ResponseTranslator` internally and calls `$engine->initialize()`. A `GuardRedisException` from initialization is swallowed only when `$engine->config()->redisFailOpen` is true; otherwise it rethrows and construction fails closed.
- `handle(Illuminate\Http\Request $request, Closure $next): Symfony\Component\HttpFoundation\Response`:
  - Wraps the request in `LaravelGuardRequest` and calls `$engine->execute()`.
  - Engine throws `Throwable`: returns `$this->translator->translate($this->engine->failClosedResponse())`. Default is `500 Security check failed`, overridable through the engine's `customErrorResponses`. The downstream handler never runs.
  - Non-null `GuardResponse` verdict: translated to a Laravel response with the exact engine status, body, and headers. The downstream handler never runs.
  - `null` verdict: `$next($request)`, passing the ORIGINAL Illuminate request untouched.

## LaravelGuardRequest

- `final class LaravelGuardRequest implements RenzoFranceschini\GuardCore\Request\GuardRequest`; wraps an `Illuminate\Http\Request`.
- `MAX_BODY_BYTES = 262144` (256 KiB, public). `body()` reads `$request->getContent()`, caches at most the cap, and replays the cache on later calls. `bodyWasTruncated()` reports whether bytes remain beyond the cap. The boundary is exact: a body of exactly 262144 bytes is read fully and not flagged truncated; one byte over is capped and flagged. The framework materializes the full body in memory (php://input); the engine only ever sees the capped prefix.
- URL adaptation: `urlPath()` from `getPathInfo()` (empty string becomes `/`), `urlScheme()` from `getScheme()`, `urlFull()` as scheme://`getHttpHost()` plus path plus query when non-empty, and `urlReplaceScheme()`, which is pure and does not mutate the request.
- `method()` upper-cases the Illuminate method. `clientHost()` returns `$request->ip()`, or `null` when it is missing or empty. Without Laravel's TrustProxies that is the connecting `REMOTE_ADDR`, and the engine's own `trusted_proxies` / `X-Forwarded-For` resolution applies; with TrustProxies configured Laravel resolves the forwarded chain first (configure one side, not both).
- `headers()` builds a `HeaderBag` from the header bag, joining multi-value headers as `a, b`; lookup is case-insensitive. `queryParams()` passes the query bag through unchanged.
- `state()` returns a fresh per-instance `RequestState` (each translated request has its own).

## ResponseTranslator

- `final class ResponseTranslator`; no constructor arguments.
- `translate(GuardResponse $response): Illuminate\Http\Response` creates a response with the guard status code and body (a null body becomes the empty string), then sets every header from the guard response's `HeaderBag`. It is an exact copy and adds nothing: no security headers, no CORS. `prepare()` is never called, so no Content-Type/charset is synthesized.

## Footguns

- No php and no composer on the dev machine: verify behavior by reading source and CI YAML; CI and docker are the executors.
- Bounded body read: payloads beyond 256 KiB, or signatures split across that boundary, are not detected and reach the downstream handler on pass. `MAX_BODY_BYTES` matches the engine's full-scan window; do not change it in isolation.
- Fail-closed is intentional: an engine exception yields `500 Security check failed`, never a pass-through. `customErrorResponses` lives in the engine's `SecurityConfig`, not in the adapter.
- With `redisFailOpen: false` and Redis down, middleware CONSTRUCTION throws `GuardRedisException`, so the failure happens before any request is handled.
- In the test runner, `REDIS_HOST=0` forces integration mode off; otherwise it probes `REDIS_HOST` (default 127.0.0.1) and `REDIS_PORT` (default 6379) and prints SKIP if unreachable. CI sets `REDIS_HOST=127.0.0.1` with a `redis:7-alpine` service.
- The adapter adds no security headers or CORS; blocked responses are exact engine translations (for example `403 Forbidden`, `429 Too many requests` with `Retry-After`). Laravel/Symfony response mechanics put `Cache-Control` on the response object itself (and `Date` when it is prepared for sending).
- `config.platform.php: 8.2.0` in composer.json pins dependency RESOLUTION to the lowest supported PHP so one lock installs across the 8.2/8.3/8.4 matrix. Keep it.
- `main` is protected and there are no shipped tags: branch, PR, no direct pushes to `main`, no new tags or releases.

## Related Projects

- `rennf93/guard-core-php`: https://github.com/Guard-Core/guard-core-php. The engine. `SecurityConfig`, `GuardEngine`, `GuardRequest`/`GuardResponse`, `HeaderBag`, `RequestState`, `RedisHandler`, and `GuardRedisException` live there, and every verdict originates there.
- `rennf93/psr15-guard`: https://github.com/Guard-Core/psr15-guard. The PSR-15 sibling adapter; the template this repository mirrors.
- `rennf93/laravel-guard`: https://github.com/Guard-Core/laravel-guard. This repository, the Laravel adapter layer of the guard-core ecosystem.
