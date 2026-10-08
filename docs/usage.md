# Usage

## Constructor

```php
final class GuardMiddleware
{
    public function __construct(GuardEngine $engine) { ... }

    public function handle(Request $request, Closure $next): Response;
}
```

`handle()` is the Laravel middleware contract. The constructor calls
`$engine->initialize()`; a `GuardRedisException` is swallowed only when
`SecurityConfig.redisFailOpen` is true, otherwise construction fails closed by
rethrowing. Block verdicts are translated by `ResponseTranslator` into an
`Illuminate\Http\Response` with the verdict status, body, and headers.

## Registration

In a Laravel application, register the middleware in the HTTP kernel (global
stack or route middleware alias) and resolve the engine from the container:

```php
// AppServiceProvider: singleton the engine so Octane workers reuse it
$this->app->singleton(GuardEngine::class, fn () => new GuardEngine(
    new SecurityConfig(/* ... */)
));

// bootstrap/app.php (Laravel 11+):
->withMiddleware(function (Middleware $middleware) {
    $middleware->append(GuardMiddleware::class);
})
```

The guard runs before the route callable; a block verdict means the route
never executes.

## Verdicts

When the engine returns a block verdict, the middleware returns the translated
`Illuminate\Http\Response` and never calls `$next`:

| Situation | Status | Body |
|---|---|---|
| Banned IP | 403 | `IP address banned` |
| Suspicious content | 400 | `Suspicious activity detected` |
| Rate limit exceeded | 429 | `Too many requests` plus `Retry-After` |
| Engine malfunction | 500 | `Security check failed` |

Bodies can be overridden globally through `SecurityConfig.customErrorResponses`.

## Fail-closed behavior

If the engine check throws, the middleware catches it and responds with the
engine's fail-closed response (`500 Security check failed`) rather than letting
the request through. A custom 500 body comes from
`customErrorResponses[500]`, not from adapter code.

## Route-scoped configuration

The engine supports per-route `RouteConfig` (required headers, per-route rate
limits, bypassed checks) keyed by a route ID on the request state. This adapter
builds the engine request internally, so there is no route-ID hook; the
engine-sanctioned equivalent is the `customRequestCheck` config closure, which
runs as the last pipeline check and can return a block verdict for any request
shape. See the [advanced example app](https://github.com/Guard-Core/laravel-guard/tree/master/examples/advanced_app)
for an admin gate built that way.
