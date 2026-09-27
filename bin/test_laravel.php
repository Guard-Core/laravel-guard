<?php

declare(strict_types=1);

use Illuminate\Http\Request;
use Illuminate\Http\Response;
use RenzoFranceschini\GuardCore\Config\SecurityConfig;
use RenzoFranceschini\GuardCore\Engine\GuardEngine;
use RenzoFranceschini\GuardCore\Request\GuardRequest;
use RenzoFranceschini\GuardCore\Request\GuardResponse;
use RenzoFranceschini\GuardCore\Redis\GuardRedisException;
use RenzoFranceschini\GuardCore\Redis\RedisHandler;
use RenzoFranceschini\GuardCore\Routing\RouteConfig;
use RenzoFranceschini\GuardCoreLaravel\GuardMiddleware;
use RenzoFranceschini\GuardCoreLaravel\LaravelGuardRequest;

require __DIR__ . '/../vendor/autoload.php';

final class T
{
    public int $passed = 0;
    public int $failed = 0;

    public function same(mixed $expected, mixed $actual, string $label): void
    {
        if ($expected === $actual) {
            $this->passed++;
            echo "ok - {$label}\n";
        } else {
            $this->failed++;
            echo "FAIL - {$label}\n";
            echo '  expected: ' . var_export($expected, true) . "\n";
            echo '  actual:   ' . var_export($actual, true) . "\n";
        }
    }

    public function ok(bool $condition, string $label): void
    {
        if ($condition) {
            $this->passed++;
            echo "ok - {$label}\n";
        } else {
            $this->failed++;
            echo "FAIL - {$label}\n";
        }
    }

    public function throws(string $class, callable $fn, string $label): void
    {
        try {
            $fn();
            $this->failed++;
            echo "FAIL - {$label}: no exception\n";
        } catch (Throwable $e) {
            $this->same($class, $e::class, $label);
        }
    }

    public function section(string $name): void
    {
        echo "\n=== {$name} ===\n";
    }
}

final class RecordingNext
{
    public int $calls = 0;

    public ?Request $seen = null;

    public Response $response;

    public function __construct()
    {
        $this->response = new Response('downstream');
    }

    public function next(): Closure
    {
        return function (Request $request): Response {
            $this->calls++;
            $this->seen = $request;

            return $this->response;
        };
    }
}

final class ThrowingPathRequest extends Request
{
    public function getPathInfo(): string
    {
        throw new RuntimeException('malformed request path');
    }
}

/**
 * @param array<string, string|list<string>> $headers
 */
function laravelRequest(
    string $path = '/',
    string $ip = '203.0.113.9',
    string $method = 'GET',
    string $query = '',
    string $body = '',
    array $headers = []
): Request {
    $request = Request::create(
        'http://ex.test' . $path . ($query !== '' ? '?' . $query : ''),
        $method,
        [],
        [],
        [],
        ['REMOTE_ADDR' => $ip],
        $body !== '' ? $body : null
    );
    foreach ($headers as $name => $value) {
        $request->headers->set($name, $value);
    }

    return $request;
}

/**
 * @return array{0: GuardMiddleware, 1: GuardEngine}
 */
function makeStack(SecurityConfig $config): array
{
    $engine = new GuardEngine($config);

    return [new GuardMiddleware($engine), $engine];
}

/**
 * @param list<array<string, mixed>> $hooks
 */
function hookCapture(array &$hooks): \Closure
{
    return function (object $request, array $payload) use (&$hooks): void {
        $hooks[] = $payload;
    };
}

$t = new T();
$attackQuery = 'q=' . urlencode("<script>alert('xss')</script>");

$t->section('Illuminate -> GuardRequest translation');
$illuminate = laravelRequest('/api/users', '198.51.100.7', 'GET', 'x=1', 'hello', ['X-Custom-Test' => ['a', 'b'], 'Content-Type' => 'text/plain']);
$illuminate->setMethod('get');
$guard = new LaravelGuardRequest($illuminate);
$t->same('/api/users', $guard->urlPath(), 'url_path');
$t->same('http', $guard->urlScheme(), 'url_scheme');
$t->same('http://ex.test/api/users?x=1', $guard->urlFull(), 'url_full');
$t->same('https://ex.test/api/users?x=1', $guard->urlReplaceScheme('https'), 'url_replace_scheme pure');
$t->same('http://ex.test/api/users?x=1', $guard->urlFull(), 'url_replace_scheme did not mutate');
$t->same('GET', $guard->method(), 'method upper-cased');
$t->same('198.51.100.7', $guard->clientHost(), 'client_host from the request ip');
$t->same('a, b', $guard->headers()->get('x-custom-test'), 'multi-value header joined');
$t->same('text/plain', $guard->headers()->get('Content-Type'), 'header get is case-insensitive');
$t->same(['x' => '1'], $guard->queryParams(), 'query_params passthrough');
$t->same('hello', $guard->body(), 'body first read');
$t->same('hello', $guard->body(), 'body cached on replay');
$t->same(true, $guard->state() !== (new LaravelGuardRequest($illuminate))->state(), 'state is per translated request');

$noAddr = Request::create('http://ex.test/');
$noAddr->server->remove('REMOTE_ADDR');
$t->same(null, (new LaravelGuardRequest($noAddr))->clientHost(), 'missing REMOTE_ADDR -> null client_host');
$emptyAddr = Request::create('http://ex.test/');
$emptyAddr->server->set('REMOTE_ADDR', '');
$t->same(null, (new LaravelGuardRequest($emptyAddr))->clientHost(), 'empty REMOTE_ADDR -> null client_host');

$t->section('bounded body read (spec 01.2)');
$guard = new LaravelGuardRequest(laravelRequest('/', '203.0.113.1', 'POST', body: str_repeat('A', 300000)));
$oversize = $guard->body();
$t->same(LaravelGuardRequest::MAX_BODY_BYTES, strlen($oversize), 'oversize body capped at MaxBodyBytes');
$t->same(true, $guard->bodyWasTruncated(), 'truncation observable');
$t->same($oversize, $guard->body(), 'replay returns the same cached prefix');
$exact = LaravelGuardRequest::MAX_BODY_BYTES;
$guard = new LaravelGuardRequest(laravelRequest('/', '203.0.113.2', 'POST', body: str_repeat('B', $exact)));
$t->same($exact, strlen($guard->body()), 'body exactly MaxBodyBytes read fully');
$t->same(false, $guard->bodyWasTruncated(), 'boundary body not flagged truncated');
$guard = new LaravelGuardRequest(laravelRequest('/', '203.0.113.3', 'POST', body: str_repeat('B', $exact + 1)));
$t->same($exact, strlen($guard->body()), 'body one byte over the cap capped');
$t->same(true, $guard->bodyWasTruncated(), 'one byte over flagged truncated');

$t->section('block verdict -> Laravel response');
$hooks = [];
$config = new SecurityConfig(enableRedis: false, blacklist: ['192.0.2.66'], onBlock: hookCapture($hooks));
[$middleware] = makeStack($config);
$next = new RecordingNext();
$blocked = $middleware->handle(laravelRequest('/private', '192.0.2.66'), $next->next());
$t->same(403, $blocked->getStatusCode(), 'blacklisted ip -> 403');
$t->same('Forbidden', $blocked->getContent(), '403 body exact');
$t->ok($blocked instanceof Response, 'block response is a Laravel-native response');
// guard-core-php parity: error responses carry the engine default security
// headers (Python reference guard_core/core/responses/factory.py applies
// apply_security_headers inside create_error_response).
$securityHeaderKeys = [
    'x-content-type-options',
    'x-frame-options',
    'x-xss-protection',
    'referrer-policy',
    'permissions-policy',
    'x-permitted-cross-domain-policies',
    'x-download-options',
    'cross-origin-embedder-policy',
    'cross-origin-opener-policy',
    'cross-origin-resource-policy',
    'strict-transport-security',
];
$allowedHeaders = ['cache-control' => true, 'date' => true, 'content-type' => true] + array_flip($securityHeaderKeys);
$missing = array_diff_key(array_flip($securityHeaderKeys), $blocked->headers->all());
$t->same([], $missing, 'engine default security headers present on plain block');
$extra = array_diff_key($blocked->headers->all(), $allowedHeaders);
$t->same([], $extra, 'no unexpected headers on plain block (framework cache-control/date, engine content-type required)');
$t->same('text/plain; charset=utf-8', $blocked->headers->get('Content-Type'), 'block response content type explicit');
$t->same(0, $next->calls, 'downstream not called on block');
$t->same('ip_security', $hooks[0]['check_name'] ?? null, 'on_block check_name');
$t->same('IP blacklisted: 192.0.2.66', $hooks[0]['reason'] ?? null, 'on_block reason');
$t->same(403, $hooks[0]['status_code'] ?? null, 'on_block status_code');
$t->same(false, $hooks[0]['passive_mode'] ?? null, 'on_block passive_mode false');

$hooks = [];
$config = new SecurityConfig(enableRedis: false, enforceHttps: true, onBlock: hookCapture($hooks));
[$middleware] = makeStack($config);
$redirect = $middleware->handle(laravelRequest('/order'), (new RecordingNext())->next());
$t->same(301, $redirect->getStatusCode(), 'https enforcement -> 301');
$t->same(['https://ex.test/order'], $redirect->headers->all('Location'), 'Location header translated to the Laravel response');

$hooks = [];
$config = new SecurityConfig(enableRedis: false, rateLimit: 2, rateLimitWindow: 60, enableRateLimiting: true, enablePenetrationDetection: false, onBlock: hookCapture($hooks));
[$middleware] = makeStack($config);
$limited = laravelRequest('/limited', '203.0.113.44');
$t->same(200, $middleware->handle($limited, (new RecordingNext())->next())->getStatusCode(), 'rate limit hit 1 passes');
$t->same(200, $middleware->handle($limited, (new RecordingNext())->next())->getStatusCode(), 'rate limit hit 2 passes');
$third = $middleware->handle($limited, (new RecordingNext())->next());
$t->same(429, $third->getStatusCode(), 'rate limit hit 3 -> 429');
$t->same('Too many requests', $third->getContent(), '429 body exact');
$t->same(['60'], $third->headers->all('Retry-After'), 'Retry-After header translated');

$hooks = [];
$config = new SecurityConfig(enableRedis: false, onBlock: hookCapture($hooks));
[$middleware] = makeStack($config);
$suspicious = $middleware->handle(laravelRequest('/search', '203.0.113.10', 'GET', $attackQuery), (new RecordingNext())->next());
$t->same(400, $suspicious->getStatusCode(), 'penetration via query param -> 400');
$t->same('Suspicious activity detected', $suspicious->getContent(), 'suspicious body exact');

$t->section('pass verdict -> downstream handler');
$hooks = [];
$config = new SecurityConfig(enableRedis: false, onBlock: hookCapture($hooks));
[$middleware] = makeStack($config);
$next = new RecordingNext();
$request = laravelRequest('/search', '203.0.113.10', 'GET', 'q=hello+world');
$passthrough = $middleware->handle($request, $next->next());
$t->same(1, $next->calls, 'downstream called exactly once on pass');
$t->same($request, $next->seen, 'downstream received the original Illuminate request');
$t->same('downstream', $passthrough->getContent(), 'downstream response returned unchanged');
$t->same([], $hooks, 'no on_block on pass');

$t->section('POST body scanned and replayed through the stack');
$config = new SecurityConfig(enableRedis: false);
[$middleware] = makeStack($config);
$t->same(200, $middleware->handle(laravelRequest('/submit', '203.0.113.20', 'POST', body: 'name=renzo&comment=ok'), (new RecordingNext())->next())->getStatusCode(), 'clean POST passes');
$t->same(400, $middleware->handle(laravelRequest('/submit', '203.0.113.21', 'POST', body: 'comment=<script>alert(1)</script>'), (new RecordingNext())->next())->getStatusCode(), 'attack body in POST -> 400');

$t->section('oversize body per spec 01 (engine only ever sees the capped prefix)');
$seenBody = null;
$config = new SecurityConfig(
    enableRedis: false,
    enablePenetrationDetection: false,
    customRequestCheck: static function (GuardRequest $request) use (&$seenBody): ?GuardResponse {
        $seenBody = $request->body();

        return null;
    }
);
[$middleware] = makeStack($config);
$beyond = laravelRequest('/upload', '203.0.113.30', 'POST', body: str_repeat('lorem ipsum ', 25000) . '<script>alert(1)</script>');
$t->same(200, $middleware->handle($beyond, (new RecordingNext())->next())->getStatusCode(), 'beyond-cap suffix does not block the request');
$t->same(LaravelGuardRequest::MAX_BODY_BYTES, strlen($seenBody ?? ''), 'engine received exactly the capped prefix');
$t->same(false, str_contains($seenBody ?? '', '<script>'), 'payload beyond the cap never reaches the engine');
$seenBody = null;
$inside = laravelRequest('/upload', '203.0.113.31', 'POST', body: str_repeat('lorem ipsum ', 20000) . '<script>alert(1)</script>');
$t->same(200, $middleware->handle($inside, (new RecordingNext())->next())->getStatusCode(), 'spy stack passes the mid-size attack request');
$t->same(true, str_contains($seenBody ?? '', '<script>alert(1)</script>'), 'payload inside the prefix reaches the engine');

$t->section('mid-size POST body detection end to end');
$config = new SecurityConfig(enableRedis: false);
[$middleware] = makeStack($config);
$filler = 'lorem ipsum dolor sit amet consectetur adipiscing elit sed do eiusmod ';
$hit = $middleware->handle(
    laravelRequest('/submit', '203.0.113.32', 'POST', body: $filler . '<script>alert(1)</script>' . $filler),
    (new RecordingNext())->next()
);
$t->same(400, $hit->getStatusCode(), 'attack inside a mid-size body is detected');
$t->same('Suspicious activity detected', $hit->getContent(), 'mid-size attack body exact');
$clean = $middleware->handle(laravelRequest('/submit', '203.0.113.33', 'POST', body: $filler), (new RecordingNext())->next());
$t->same(200, $clean->getStatusCode(), 'clean mid-size body passes');

$t->section('whitelist through the full stack');
$hooks = [];
$config = new SecurityConfig(enableRedis: false, whitelist: ['203.0.113.50'], onBlock: hookCapture($hooks));
[$middleware] = makeStack($config);
$allowed = $middleware->handle(laravelRequest('/search', '203.0.113.50', 'GET', $attackQuery), (new RecordingNext())->next());
$t->same(200, $allowed->getStatusCode(), 'whitelisted ip passes despite attack payload');
$other = $middleware->handle(laravelRequest('/search', '203.0.113.99', 'GET', $attackQuery), (new RecordingNext())->next());
$t->same(403, $other->getStatusCode(), 'non-whitelisted ip -> 403');
$t->same('Forbidden', $other->getContent(), 'whitelist miss body exact');

$t->section('exclusion paths through the full stack');
$hooks = [];
$config = new SecurityConfig(enableRedis: false, blacklist: ['192.0.2.66'], onBlock: hookCapture($hooks));
[$middleware] = makeStack($config);
$docs = $middleware->handle(laravelRequest('/docs/api', '203.0.113.60', 'GET', $attackQuery), (new RecordingNext())->next());
$t->same(200, $docs->getStatusCode(), 'attack on excluded path passes (exclusion-scoped pipeline)');
$search = $middleware->handle(laravelRequest('/search', '203.0.113.60', 'GET', $attackQuery), (new RecordingNext())->next());
$t->same(400, $search->getStatusCode(), 'same attack outside excluded path -> 400');
$docsBanned = $middleware->handle(laravelRequest('/docs/api', '192.0.2.66'), (new RecordingNext())->next());
$t->same(403, $docsBanned->getStatusCode(), 'ip_security still enforced on excluded paths');
$favicon = $middleware->handle(laravelRequest('/favicon.ico', '203.0.113.60', 'GET', $attackQuery), (new RecordingNext())->next());
$t->same(200, $favicon->getStatusCode(), 'default exclusion favicon.ico passes');

$t->section('passive mode');
$hooks = [];
$config = new SecurityConfig(enableRedis: false, passiveMode: true, onBlock: hookCapture($hooks));
[$middleware] = makeStack($config);
$next = new RecordingNext();
$passive = $middleware->handle(laravelRequest('/search', '203.0.113.70', 'GET', $attackQuery), $next->next());
$t->same(200, $passive->getStatusCode(), 'passive mode does not block');
$t->same(1, $next->calls, 'passive mode hands request to the downstream handler');
$t->same([], $hooks, 'passive mode fires no on_block from suspicious_activity');

$t->section('fail-closed on engine malfunction (conformance.md)');
$config = new SecurityConfig(enableRedis: false);
[$middleware] = makeStack($config);
$next = new RecordingNext();
$malformed = $middleware->handle(new ThrowingPathRequest([], [], [], [], [], ['REMOTE_ADDR' => '203.0.113.90']), $next->next());
$t->same(500, $malformed->getStatusCode(), 'engine malfunction -> fail-closed 500');
$t->same('Security check failed', $malformed->getContent(), 'fail-closed body exact');
$t->same(0, $next->calls, 'downstream not called on malfunction');

$config = new SecurityConfig(enableRedis: false, customErrorResponses: [500 => 'Security unavailable']);
[$middleware] = makeStack($config);
$custom = $middleware->handle(new ThrowingPathRequest([], [], [], [], [], ['REMOTE_ADDR' => '203.0.113.91']), (new RecordingNext())->next());
$t->same('Security unavailable', $custom->getContent(), 'custom_error_responses honored on fail-closed');

$config = new SecurityConfig(enableRedis: false, customRequestCheck: static fn (): never => throw new RuntimeException('validator exploded'));
[$middleware] = makeStack($config);
$pipelineFail = $middleware->handle(laravelRequest('/x', '203.0.113.80'), (new RecordingNext())->next());
$t->same(500, $pipelineFail->getStatusCode(), 'check exception -> pipeline fail-secure 500');
$t->same('Security check failed', $pipelineFail->getContent(), 'pipeline fail-secure body exact');

$config = new SecurityConfig(enableRedis: true, redisFailOpen: false);
$engine = new GuardEngine($config, new RedisHandler(enableRedis: true, prefix: 'guard_core_laravelu:', host: '127.0.0.1', port: 1));
$t->throws(
    GuardRedisException::class,
    static function () use ($config, $engine): void {
        new GuardMiddleware($engine);
    },
    'redis down + redis_fail_open=false: middleware construction fails closed'
);

$config = new SecurityConfig(enableRedis: true, redisFailOpen: true);
$engine = new GuardEngine($config, new RedisHandler(enableRedis: true, prefix: 'guard_core_laravelu:', host: '127.0.0.1', port: 1));
$middleware = new GuardMiddleware($engine);
$openPass = $middleware->handle(laravelRequest('/x', '203.0.113.81'), (new RecordingNext())->next());
$t->same(200, $openPass->getStatusCode(), 'redis down + redis_fail_open=true: construction survives, request passes (bounded fail-open)');

$t->section('pass-through security headers (engine responseHeaders on the way out)');
$config = new SecurityConfig(enableRedis: false, enablePenetrationDetection: false);
[$middleware] = makeStack($config);
$next = new RecordingNext();
$passed = $middleware->handle(laravelRequest('/page', '203.0.113.110'), $next->next());
$t->same(200, $passed->getStatusCode(), 'pass-through status preserved');
$t->same('downstream', $passed->getContent(), 'pass-through body preserved');
$missing = array_diff_key(array_flip($securityHeaderKeys), $passed->headers->all());
$t->same([], $missing, 'engine default security headers applied to the pass-through response');
$extra = array_diff_key($passed->headers->all(), $allowedHeaders);
$t->same([], $extra, 'pass-through response carries nothing beyond engine headers and framework basics');
$config = new SecurityConfig(enableRedis: false, enablePenetrationDetection: false, securityHeaders: ['enabled' => false]);
[$middleware] = makeStack($config);
$passed = $middleware->handle(laravelRequest('/page', '203.0.113.111'), (new RecordingNext())->next());
$t->same(true, array_diff_key(array_flip($securityHeaderKeys), $passed->headers->all()) !== [], 'headers disabled: no engine security headers on the pass-through response');

$t->section('pass-through CORS response headers');
$config = new SecurityConfig(enableRedis: false, enablePenetrationDetection: false, enableCors: true, corsAllowOrigins: ['https://app.test']);
[$middleware] = makeStack($config);
$corsReq = laravelRequest('/page', '203.0.113.120', 'GET', '', '', ['Origin' => 'https://app.test']);
$passed = $middleware->handle($corsReq, (new RecordingNext())->next());
$t->same(['https://app.test'], $passed->headers->all('Access-Control-Allow-Origin'), 'allowed origin echoed onto the pass-through response');
$noOrigin = $middleware->handle(laravelRequest('/page', '203.0.113.121'), (new RecordingNext())->next());
$t->same([], $noOrigin->headers->all('Access-Control-Allow-Origin'), 'no Origin header: no CORS headers on the pass-through response');
$disallowed = $middleware->handle(laravelRequest('/page', '203.0.113.122', 'GET', '', '', ['Origin' => 'https://evil.test']), (new RecordingNext())->next());
$t->same([], $disallowed->headers->all('Access-Control-Allow-Origin'), 'disallowed origin: no CORS headers on the pass-through response');

$t->section('behavior return rules over the pass-through response');
$config = new SecurityConfig(
    enableRedis: false,
    enablePenetrationDetection: false,
    globalBehaviorRules: [['rule_type' => 'return_pattern', 'threshold' => 1, 'pattern' => 'status:404', 'action' => 'ban', 'window' => 60]]
);
[$middleware] = makeStack($config);
$recorder = new RecordingNext();
$recorder->response = new Response('nope', 404);
$middleware->handle(laravelRequest('/missing', '203.0.113.130'), $recorder->next());
$middleware->handle(laravelRequest('/missing', '203.0.113.130'), $recorder->next());
$banned = $middleware->handle(laravelRequest('/missing', '203.0.113.130'), (new RecordingNext())->next());
$t->same(403, $banned->getStatusCode(), 'status-only return rule banned the ip (threshold trips strictly greater)');
$t->ok(str_contains($banned->getContent(), 'banned'), 'ban body reports the ban');

$t->section('return rules with body patterns: scan flag and inspect-bytes budget');
$marker = 'leaked-secret-trailer';
$baseRules = [['rule_type' => 'return_pattern', 'threshold' => 1, 'pattern' => $marker, 'action' => 'ban', 'window' => 60]];
$config = new SecurityConfig(
    enableRedis: false,
    enablePenetrationDetection: false,
    behaviorScanResponseBody: true,
    behaviorMaxResponseBodyInspectBytes: 1024,
    globalBehaviorRules: $baseRules
);
[$middleware] = makeStack($config);
$recorder = new RecordingNext();
$recorder->response = new Response(str_repeat('a', 900) . $marker, 200);
$middleware->handle(laravelRequest('/report', '203.0.113.131'), $recorder->next());
$middleware->handle(laravelRequest('/report', '203.0.113.131'), $recorder->next());
$banned = $middleware->handle(laravelRequest('/report', '203.0.113.131'), (new RecordingNext())->next());
$t->same(403, $banned->getStatusCode(), 'body pattern inside the inspect budget triggered the ban');

$config = new SecurityConfig(
    enableRedis: false,
    enablePenetrationDetection: false,
    behaviorScanResponseBody: true,
    behaviorMaxResponseBodyInspectBytes: 1024,
    globalBehaviorRules: $baseRules
);
[$middleware] = makeStack($config);
$recorder = new RecordingNext();
$recorder->response = new Response(str_repeat('a', 1024) . $marker, 200);
$passed = $middleware->handle(laravelRequest('/report', '203.0.113.132'), $recorder->next());
$t->same(200, $passed->getStatusCode(), 'marker at the budget edge stays unflagged and unmodified');
$t->same(200, $middleware->handle(laravelRequest('/report', '203.0.113.132'), (new RecordingNext())->next())->getStatusCode(), 'pattern beyond the inspect budget never triggers');

$config = new SecurityConfig(
    enableRedis: false,
    enablePenetrationDetection: false,
    behaviorScanResponseBody: true,
    behaviorMaxResponseBodyInspectBytes: 1024,
    globalBehaviorRules: $baseRules
);
[$middleware] = makeStack($config);
$bigBody = str_repeat('a', 5000) . $marker;
$recorder = new RecordingNext();
$recorder->response = new Response($bigBody, 200);
$passed = $middleware->handle(laravelRequest('/report', '203.0.113.133'), $recorder->next());
$t->same(strlen($bigBody), strlen((string) $passed->getContent()), 'large pass-through body not truncated by the capture');
$t->throws(
    \InvalidArgumentException::class,
    static function () use ($baseRules): void {
        new SecurityConfig(enableRedis: false, globalBehaviorRules: $baseRules, behaviorScanResponseBody: false);
    },
    'body pattern with scan off rejected at config construction'
);

$t->section('per-route config through the middleware route map');
$hooks = [];
$routeConfig = new RouteConfig(enableSuspiciousDetection: false);
$middleware = new GuardMiddleware(
    new GuardEngine(new SecurityConfig(enableRedis: false, onBlock: hookCapture($hooks))),
    routes: ['/open/' => $routeConfig]
);
$open = $middleware->handle(laravelRequest('/open/section', '203.0.113.140', 'GET', $attackQuery), (new RecordingNext())->next());
$t->same(200, $open->getStatusCode(), 'attack on a route with detection disabled passes');
$guarded = $middleware->handle(laravelRequest('/search', '203.0.113.140', 'GET', $attackQuery), (new RecordingNext())->next());
$t->same(400, $guarded->getStatusCode(), 'same attack on an unconfigured route still blocks');

$hooks = [];
$config = new SecurityConfig(enableRedis: false, onBlock: hookCapture($hooks));
$routeConfig = new RouteConfig(behaviorRules: [new \RenzoFranceschini\GuardCore\Behavior\BehaviorRule('usage', 1, window: 60, action: 'ban')]);
$middleware = new GuardMiddleware(new GuardEngine($config), routes: ['/chatty/' => $routeConfig]);
$middleware->handle(laravelRequest('/chatty/feed', '203.0.113.141'), (new RecordingNext())->next());
$middleware->handle(laravelRequest('/chatty/feed', '203.0.113.141'), (new RecordingNext())->next());
$banned = $middleware->handle(laravelRequest('/chatty/feed', '203.0.113.141'), (new RecordingNext())->next());
$t->same(403, $banned->getStatusCode(), 'route usage rule banned the ip after the threshold');

$seenPath = null;
$config = new SecurityConfig(enableRedis: false);
$middleware = new GuardMiddleware(
    new GuardEngine($config),
    routeResolver: static function (Request $request) use (&$seenPath): ?RouteConfig {
        $seenPath = $request->getPathInfo();

        return $request->getPathInfo() === '/dynamic' ? new RouteConfig(enableSuspiciousDetection: false) : null;
    }
);
$dynamic = $middleware->handle(laravelRequest('/dynamic', '203.0.113.142', 'GET', $attackQuery), (new RecordingNext())->next());
$t->same('/dynamic', $seenPath, 'custom resolver received the raw Illuminate request');
$t->same(200, $dynamic->getStatusCode(), 'custom resolver route skips detection');
$static = $middleware->handle(laravelRequest('/search', '203.0.113.143', 'GET', $attackQuery), (new RecordingNext())->next());
$t->same(400, $static->getStatusCode(), 'custom resolver returning null keeps global enforcement');

$t->section('geo country config through the public adapter surface');
final class FakeCountryResolver implements \RenzoFranceschini\GuardCore\GeoIp\CountryResolver
{
    public function __construct(private readonly ?string $country)
    {
    }

    public function getCountry(string $ip): ?string
    {
        return $this->country;
    }
}

$hooks = [];
$config = new SecurityConfig(
    enableRedis: false,
    enablePenetrationDetection: false,
    blockedCountries: ['CN'],
    geoIpHandler: new FakeCountryResolver('CN'),
    onBlock: hookCapture($hooks)
);
[$middleware] = makeStack($config);
$blockedCountry = $middleware->handle(laravelRequest('/download', '203.0.113.150'), (new RecordingNext())->next());
$t->same(403, $blockedCountry->getStatusCode(), 'blocked country -> 403 through the adapter');
$t->same('Forbidden', $blockedCountry->getContent(), 'country block body exact');
$config = new SecurityConfig(
    enableRedis: false,
    enablePenetrationDetection: false,
    whitelistCountries: ['DE'],
    geoIpHandler: new FakeCountryResolver('CN')
);
[$middleware] = makeStack($config);
$notAllowed = $middleware->handle(laravelRequest('/download', '203.0.113.151'), (new RecordingNext())->next());
$t->same(403, $notAllowed->getStatusCode(), 'country outside the allowlist -> 403');
$config = new SecurityConfig(
    enableRedis: false,
    enablePenetrationDetection: false,
    whitelistCountries: ['DE'],
    geoIpHandler: new FakeCountryResolver('DE')
);
[$middleware] = makeStack($config);
$allowedCountry = $middleware->handle(laravelRequest('/download', '203.0.113.152'), (new RecordingNext())->next());
$t->same(200, $allowedCountry->getStatusCode(), 'allowlisted country passes');

$t->section('geo rate-limit tiers via the config geo resolver bridge');
$config = new SecurityConfig(enableRedis: false, enablePenetrationDetection: false);
$middleware = new GuardMiddleware(
    new GuardEngine($config),
    routes: ['/geo/' => new RouteConfig(geoRateLimits: ['CN' => ['limit' => 1, 'window' => 60]])],
    geoRateLimitResolver: new FakeCountryResolver('CN')
);
$geoReq = laravelRequest('/geo/data', '203.0.113.160');
$t->same(200, $middleware->handle($geoReq, (new RecordingNext())->next())->getStatusCode(), 'geo tier hit 1 passes');
$limited = $middleware->handle(laravelRequest('/geo/data', '203.0.113.160'), (new RecordingNext())->next());
$t->same(429, $limited->getStatusCode(), 'geo tier hit 2 -> 429');
$t->same(['60'], $limited->headers->all('Retry-After'), 'geo tier Retry-After carries the tier window');

$t->section('integration: shared state over real redis');
$integration = getenv('REDIS_HOST') !== '0';
if ($integration) {
    $host = getenv('REDIS_HOST') ?: '127.0.0.1';
    $port = (int) (getenv('REDIS_PORT') ?: 6379);
    $socket = @fsockopen($host, $port, $errno, $errstr, 1.0);
    if ($socket === false) {
        echo "SKIP: integration mode: no redis reachable at {$host}:{$port} ({$errstr}); unit coverage stands\n";
    } else {
        fclose($socket);
        runRedisIntegration($t);
    }
} else {
    echo "\nNOTE: integration mode off (set REDIS_HOST to run the adapter over real redis)\n";
}

function runRedisIntegration(T $t): void
{
    putenv('REDIS_PREFIX=guard_core_laravel:' . bin2hex(random_bytes(3)) . ':');
    $redis = RedisHandler::fromEnv();
    $redis->initialize();
    $conn = $redis->connection();
    foreach ($redis->keys('*') as $key) {
        $conn->del((string) $key);
    }

    $t->section('integration: rate limit shared across engine instances');
    $configA = new SecurityConfig(rateLimit: 2, rateLimitWindow: 60, enableRateLimiting: true, enablePenetrationDetection: false);
    $engineA = new GuardEngine($configA, $redis);
    $middlewareA = new GuardMiddleware($engineA);
    $limited = laravelRequest('/limited', '192.0.2.11');
    $t->same(200, $middlewareA->handle($limited, (new RecordingNext())->next())->getStatusCode(), 'engine A hit 1 passes');
    $t->same(200, $middlewareA->handle($limited, (new RecordingNext())->next())->getStatusCode(), 'engine A hit 2 passes');
    $t->same(429, $middlewareA->handle($limited, (new RecordingNext())->next())->getStatusCode(), 'engine A hit 3 -> 429 over redis');

    $configB = new SecurityConfig(rateLimit: 2, rateLimitWindow: 60, enableRateLimiting: true, enablePenetrationDetection: false);
    $engineB = new GuardEngine($configB, $redis);
    $middlewareB = new GuardMiddleware($engineB);
    $t->same(429, $middlewareB->handle($limited, (new RecordingNext())->next())->getStatusCode(), 'fresh engine B sees the shared bucket immediately');

    $t->section('integration: ban written by engine A blocks engine B through the adapter');
    $t->same(true, $engineA->banManager()->ban('192.0.2.66', 60, 'laravel_integration'), 'engine A bans 192.0.2.66');
    $banned = $middlewareB->handle(laravelRequest('/private', '192.0.2.66'), (new RecordingNext())->next());
    $t->same(403, $banned->getStatusCode(), 'engine B blocks the banned ip');
    $t->same('IP address banned', $banned->getContent(), 'ban body exact');

    foreach ($redis->keys('*') as $key) {
        $conn->del((string) $key);
    }
}

$total = $t->passed + $t->failed;
echo "\nPassed: {$t->passed}, Failed: {$t->failed}\n";
echo "{$t->passed}/{$total}" . ($t->failed === 0 ? ' GREEN' : ' RED') . "\n";
exit($t->failed === 0 ? 0 : 1);
