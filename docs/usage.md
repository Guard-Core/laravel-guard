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

## Telemetry agent and dynamic rules

The adapter forwards an optional `agentHandler` to the engine (`GuardEngine::setAgentHandler()`), so security events, metrics and dashboard dynamic rules flow through [guard-agent-php](https://github.com/Guard-Core/guard-agent-php). Install it with `composer require rennf93/guard-agent-php`, create and start the agent the container binding that builds the middleware, pass it as the `agentHandler` argument, and declare it in the config with `enableAgent: true`:

```php
use RenzoFranceschini\GuardAgent\GuardAgent;
use RenzoFranceschini\GuardCore\Config\SecurityConfig;
use RenzoFranceschini\GuardCore\Engine\GuardEngine;

$agent = new GuardAgent([
    'apiKey' => $_ENV['GUARD_API_KEY'],
    'endpoint' => 'https://api.guard-core.com',
    'projectId' => 'my-project',
]);
$agent->start();

$config = new SecurityConfig(
    enableRedis: true,
    enableAgent: true,
    enableDynamicRules: true,
    dynamicRuleInterval: 300,
    enableEnrichment: true,
    agentProjectId: 'my-project',
);

return new GuardMiddleware(new GuardEngine($config), agentHandler: $agent);

register_shutdown_function(static function () use ($agent): void {
    $agent->flushBuffer();
    $agent->stop();
});
```

`enableDynamicRules` and `enableEnrichment` require `enableAgent`, and dynamic rules need an agent handler that exposes `getDynamicRules()` (`GuardAgent` does). The engine builds the dynamic rule manager itself and checks for new rules on the first request and then every `dynamicRuleInterval` seconds (default 300, minimum 60), because a PHP request process has no background loop. Without an agent handler the engine warns and stays on the static config. `agentStats()` on the adapter reports `{enabled: false, degraded: false}` without a handler and merges the handler's `getStats()` map with one. Laravel can ship the buffered telemetry from `terminate()`; in plain FPM the shutdown function above does the same.

## Response modifier

`SecurityConfig::customResponseModifier` is a `Closure(GuardResponse): GuardResponse` that the engine applies once to every response it returns itself (blocks, redirects and the fail-closed response), after the security headers and before the CORS verdict headers. This adapter applies the same modifier to the pass-through responses it sends, through `GuardEngine::applyResponseModifier()`, including excluded-path responses and responses of requests that bypass all checks, and it never applies it twice to a response the engine already modified. The order on a pass-through response is security headers, modifier, then CORS verdict headers, so the CORS policy has the last word on its own headers.

```php
use RenzoFranceschini\GuardCore\Request\GuardResponse;

$config = new SecurityConfig(
    customResponseModifier: static function (GuardResponse $response): GuardResponse {
        $response->headers()->set('X-Served-By', 'edge-1');

        return $response;
    },
);
```

The modifier sees the status, the headers (`Set-Cookie` is not exposed) and the body, and the changes are merged back onto the Illuminate\Http\Response; a streamed or file response keeps its body (only its status and headers can change). A modifier that throws leaves the response unmodified. Nothing is read from the response while no modifier is configured. The modifier seam needs guard-core-php 4.4.0 or later.

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
