<?php

declare(strict_types=1);

use Illuminate\Http\Request;
use Illuminate\Http\Response;
use RenzoFranceschini\GuardCore\Config\SecurityConfig;
use RenzoFranceschini\GuardCore\Engine\GuardEngine;
use RenzoFranceschini\GuardCoreLaravel\GuardMiddleware;

require __DIR__ . '/../vendor/autoload.php';

/**
 * Minimal guarded app. The router is the $next closure of the Laravel
 * middleware contract; every request passes through GuardMiddleware (the
 * laravel-guard adapter for guard-core-php) before it sees one. The
 * Illuminate components are used as a minimal bootstrap (no full framework
 * skeleton): Request::capture() for input, Illuminate\Http\Response for
 * output, Response::send() for emission.
 */
$guard = new GuardMiddleware(new GuardEngine(new SecurityConfig(
    enableRedis: true,
    redisPrefix: getenv('REDIS_PREFIX') ?: 'guard_core_laravel:',
    redisFailOpen: true,
    enableRateLimiting: true,
    rateLimit: 100,
    rateLimitWindow: 60,
    endpointRateLimits: [
        '/rate/strict' => ['limit' => 1, 'window' => 10],
    ],
    enableIpBanning: true,
    autoBanThreshold: 5,
    autoBanDuration: 300,
    // The engine's strike counter for auto-ban lives in memory per engine
    // instance, so under PHP shared-nothing a repeated-violation auto-ban
    // never accumulates across requests. The demo pins the xss threat ban at
    // threshold 1 instead: the first XSS payload trips the ban, and the ban
    // itself is Redis-backed and deterministic across requests.
    threatBanConfig: ['xss' => ['threshold' => 1, 'duration' => 300]],
    customErrorResponses: [403 => 'Blocked by laravel-guard'],
    excludePaths: ['/health'],
)));

$next = static function (Request $request) use ($guard): Response {
    return match ($request->getPathInfo()) {
        '/', '' => new Response('ok'),
        '/health' => new Response('healthy'),
        '/rate/strict' => new Response('strict ok'),
        '/search' => new Response(
            'search: ' . htmlspecialchars((string) ($request->query->get('q', '')), ENT_QUOTES)
        ),
        default => new Response('not found', 404),
    };
};

$guard->handle(Request::capture(), $next)->send();
