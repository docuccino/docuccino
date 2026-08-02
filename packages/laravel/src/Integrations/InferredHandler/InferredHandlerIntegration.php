<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Integrations\InferredHandler;

/**
 * Entry point for the inferred exception-handler tier (design §6 flagship). Always on: it documents
 * whatever error contract the app actually implements (render callbacks, exception `render()`,
 * `Responsable` exceptions) and stays inert — deferring to the next tier — for an app that renders
 * errors in a way it cannot fold to a JSON response. The mapper is container-resolved so its
 * {@see HandlerReflector} receives the booted exception handler.
 */
final class InferredHandlerIntegration
{
    /**
     * @return list<class-string>
     */
    public static function extensions(): array
    {
        return [
            InferredHandlerExceptionToResponse::class,
        ];
    }
}
