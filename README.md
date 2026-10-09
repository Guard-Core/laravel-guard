<p align="center">
    <a href="https://guard-core.github.io/guard-core/latest/">
        <img src="https://guard-core.github.io/guard-core/latest/assets/guard_core_legend.svg" alt="Guard Core">
    </a>
</p>

___

<p align="center">
    <strong>Laravel middleware adapter for [guard-core-php](https://github.com/Guard-Core/guard-core-php): maps `Illuminate\Http\Request` objects to the guard-core engine and translates block verdicts back to Laravel-native responses. Works with Laravel 10, 11, and 12.</strong>
</p>

<p align="center">
    <a href="https://packagist.org/packages/rennf93/laravel-guard">
        <img src="https://img.shields.io/packagist/v/rennf93/laravel-guard?color=0080ff" alt="Packagist version">
    </a>
    <a href="https://guard-core.github.io/laravel-guard/latest/">
        <img src="https://img.shields.io/badge/docs-latest-0080ff.svg" alt="Docs">
    </a>
    <a href="https://github.com/Guard-Core/laravel-guard/actions/workflows/release.yml">
        <img src="https://github.com/Guard-Core/laravel-guard/actions/workflows/release.yml/badge.svg" alt="Release">
    </a>
    <a href="https://opensource.org/licenses/MIT">
        <img src="https://img.shields.io/badge/License-MIT-yellow.svg" alt="License">
    </a>
    <a href="https://github.com/Guard-Core/laravel-guard/actions/workflows/ci.yml">
        <img src="https://github.com/Guard-Core/laravel-guard/actions/workflows/ci.yml/badge.svg" alt="CI">
    </a>
</p>

<p align="center">
    <a href="https://github.com/Guard-Core/laravel-guard/actions/workflows/pages/pages-build-deployment">
        <img src="https://github.com/Guard-Core/laravel-guard/actions/workflows/pages/pages-build-deployment/badge.svg?branch=gh-pages" alt="PagesBuildDeployment">
    </a>
    <a href="https://github.com/Guard-Core/laravel-guard/actions/workflows/docs.yml">
        <img src="https://github.com/Guard-Core/laravel-guard/actions/workflows/docs.yml/badge.svg" alt="DocsUpdate">
    </a>
    <img src="https://img.shields.io/github/last-commit/Guard-Core/laravel-guard?style=flat&amp;logo=git&amp;logoColor=white&amp;color=0080ff" alt="last-commit">
</p>

<p align="center">
    <img src="https://img.shields.io/badge/Laravel-FF2D20.svg?style=flat&logo=laravel&logoColor=white" alt="Laravel"> <img src="https://img.shields.io/badge/PHP-777BB4.svg?style=flat&logo=php&logoColor=white" alt="PHP">
    <a href="https://packagist.org/packages/rennf93/laravel-guard">
        <img src="https://img.shields.io/packagist/dm/rennf93/laravel-guard" alt="Downloads">
    </a>
</p>

<p align="center">
    <a href="https://guard-core.com">Website</a> &middot;
    <a href="https://guard-core.github.io/laravel-guard/latest/">Docs</a> &middot;
    <a href="https://playground.guard-core.com">Playground</a> &middot;
    <a href="https://app.guard-core.com">Dashboard</a> &middot;
    <a href="https://discord.gg/ZW7ZJbjMkK">Discord</a>
</p>

---

## Install

```bash
composer require rennf93/laravel-guard
```

## Usage

Bind the middleware in the container and register it globally:

```php
use Illuminate\Http\Request;
use RenzoFranceschini\GuardCore\Config\SecurityConfig;
use RenzoFranceschini\GuardCore\Engine\GuardEngine;
use RenzoFranceschini\GuardCoreLaravel\GuardMiddleware;

// In a service provider (or any container binding)
$this->app->bind(GuardMiddleware::class, function () {
    $config = new SecurityConfig(
        enableRedis: false,
        blacklist: ['192.0.2.0/24'],
        rateLimit: 100,
        rateLimitWindow: 60,
        enableRateLimiting: true,
    );

    return new GuardMiddleware(new GuardEngine($config));
});
```

```php
// Laravel 11/12, bootstrap/app.php
->withMiddleware(function (Middleware $middleware) {
    $middleware->prepend(GuardMiddleware::class);
});
```

```php
// Laravel 10, app/Http/Kernel.php
protected $middleware = [
    // ...
    \RenzoFranceschini\GuardCoreLaravel\GuardMiddleware::class,
];
```

Blocked requests get the engine's block verdict translated exactly (status, body, headers) as an `Illuminate\Http\Response`: `403 Forbidden` for a blacklisted IP, `429 Too many requests` with `Retry-After` for a rate limit hit.

Pass-through responses are finished by the middleware too: the engine's security headers and CORS verdict headers are merged on top of the handler's response, and the engine's behavioral return rules observe the response status code plus a body prefix bounded by `behaviorMaxResponseBodyInspectBytes` (only while `behaviorScanResponseBody` is on). Per-route configuration attaches through the middleware's route map or a custom resolver:

```php
use RenzoFranceschini\GuardCore\Routing\RouteConfig;
use RenzoFranceschini\GuardCoreLaravel\GuardMiddleware;

return new GuardMiddleware(
    new GuardEngine($config),
    routes: [
        '/docs/' => new RouteConfig(enableSuspiciousDetection: false),
        '/api/' => new RouteConfig(rateLimit: 5, rateLimitWindow: 10),
    ],
    // optional: country resolver for RouteConfig geoRateLimits tiers
    geoRateLimitResolver: $myResolver,
    // optional: custom resolver receiving the Illuminate request
    routeResolver: fn (Request $r) => $r->is('admin/*') ? new RouteConfig(bypassedChecks: ['rate_limit']) : null,
);
```

`routes` patterns match a path exactly or as a prefix when they end with `/`. Geo rate-limit tiers need a country resolver: pass `geoRateLimitResolver` explicitly, or configure `geoIpHandler` together with `blockedCountries`/`whitelistCountries` (the engine keeps the injected handler only when country lists are set) and the middleware bridges it onto the engine's rate-limit handler automatically.

## Lifecycle

PHP shared-nothing applies: construct `GuardEngine` (and therefore `GuardMiddleware`) per request in classic FPM, or per worker under Octane/FrankenPHP. The middleware holds no mutable state of its own. In-memory fallbacks are per-request safety nets; distributed rate limits, IP bans, and cloud-range caches require Redis (set `enableRedis: true` and point `REDIS_HOST`/`REDIS_PORT` at your instance).

## Behavior notes

- Fail-closed: if the engine throws, the middleware returns the engine's fail-closed response (`500 Security check failed`, honorably overridden by `customErrorResponses`) instead of letting the request through.
- Bounded body read: the request body is scanned as a prefix of at most 256 KiB (`LaravelGuardRequest::MAX_BODY_BYTES`, matching the engine's full-scan window). The framework materializes the full body in memory; the engine only ever sees the capped prefix. Payloads beyond the prefix, or signatures split across its boundary, are not detected.
- Client address: the adapter maps the engine's `clientHost()` onto `$request->ip()`. Without Laravel's TrustProxies middleware that is the connecting `REMOTE_ADDR`, and the engine's own `trusted_proxies` / `X-Forwarded-For` resolution applies. With Laravel's TrustProxies configured, Laravel resolves the forwarded chain first and the engine sees the resolved client. Pick one side to do the resolving; configuring both can double-hop.
- With `redisFailOpen: true` the middleware constructs and serves requests even when Redis is unreachable; with `redisFailOpen: false` construction fails closed.
- No security headers or CORS are added by this adapter. (Laravel/Symfony response mechanics put a `Date` and a private `Cache-Control` on every response object; the engine's own headers are copied exactly.)

## Testing

```bash
composer lint
composer test
```

`composer test` runs the plain-PHP suite in `bin/test_laravel.php` (unit coverage always; set `REDIS_HOST` to a reachable Redis to include the shared-state integration cases).

## Status

Released: v1.1.0 on Packagist. The engine floor is `rennf93/guard-core-php ^4.1.0`; no 4.1.0 of the engine is currently published (its tag is absent and Packagist's latest is v4.0.4), so public resolution is collapsed until the synchronized 4.2.0 train retags the engine - CI resolves the engine from the master sibling checkout in the meantime.

## License

MIT
