<?php

declare(strict_types=1);

namespace RenzoFranceschini\GuardCoreLaravel;

use Illuminate\Http\JsonResponse;
use RenzoFranceschini\GuardCore\Engine\GuardEngine;

/**
 * GET /_guard/status handler: serves the engine's initialization status
 * (per-component enabled/ok/error from initialize()), mirroring
 * fastapi-guard's add_status_route. Register it with your engine instance:
 *
 *   Route::get('/_guard/status', new GuardStatusController($engine));
 */
final class GuardStatusController
{
    public function __construct(private readonly GuardEngine $engine)
    {
    }

    public function __invoke(): JsonResponse
    {
        return new JsonResponse($this->engine->initializationStatus());
    }
}
