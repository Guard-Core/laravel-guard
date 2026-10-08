# laravel-guard

`laravel-guard` is the official Laravel adapter for
[guard-core-php](https://github.com/Guard-Core/guard-core-php), the PHP port of
the guard-core security engine. It maps Illuminate HTTP requests into the
engine pipeline: penetration detection, rate limiting, IP banning, and verdict
responses translated back to Laravel-native responses.

All security logic lives in the engine; this package is a thin shim that
translates an `Illuminate\Http\Request` into a `GuardRequest`, runs the
engine, and translates the block verdict into an
`Illuminate\Http\Response` when one arrives.

## Installation

```bash
composer require rennf93/laravel-guard
```

Requires PHP 8.2 or later. Until `rennf93/guard-core-php` has a Packagist
distribution, point composer at its repository and allow dev stability:

```json
{
    "minimum-stability": "dev",
    "prefer-stable": true,
    "repositories": [
        { "type": "vcs", "url": "https://github.com/Guard-Core/guard-core-php" }
    ]
}
```

## Quick start

```php
use RenzoFranceschini\GuardCore\Config\SecurityConfig;
use RenzoFranceschini\GuardCore\Engine\GuardEngine;
use RenzoFranceschini\GuardCoreLaravel\GuardMiddleware;

$guard = new GuardMiddleware(new GuardEngine(new SecurityConfig(
    enableRedis: true,
    redisPrefix: 'guard_core:',
    rateLimit: 100,
    rateLimitWindow: 60,
)));

// In a Laravel application:
// $app->singleton(GuardMiddleware::class);
// registered as global middleware or route middleware
```

Blocked requests get the engine's `GuardResponse` translated exactly (status,
body, headers). Passing requests continue into the wrapped stack untouched.

## What the middleware handles

- Client identity: `$request->ip()` (Illuminate trusted-proxy resolution
  applies), with the engine's own `trustedProxies` available as an alternative
- Headers: joined into the engine's case-insensitive `HeaderBag`
- Body: the first 256 KiB are shown to the engine
  (`LaravelGuardRequest::MAX_BODY_BYTES`)
- Fail-closed: engine throws become `500` with a fixed, non-leaky message
- Statelessness: the middleware holds no mutable state; PHP shared-nothing
  applies (per request under FPM, per worker under Octane)

See [Usage](usage.md) for the full adapter surface and
[Configuration](configuration.md) for engine tuning. Runnable apps live in the
[examples](https://github.com/Guard-Core/laravel-guard/tree/master/examples)
directory.
