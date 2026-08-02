<?php

declare(strict_types=1);

use Docuccino\Laravel\Exceptions\DefaultExceptionToResponse;
use Docuccino\Laravel\Integrations\FrameworkErrors\FrameworkErrorsExceptionToResponse;
use Docuccino\Laravel\Integrations\InferredHandler\InferredHandlerExceptionToResponse;
use Docuccino\Laravel\Integrations\ProblemDetails\ProblemDetailsExceptionToResponse;
use Docuccino\Laravel\Registry\DefaultExtensions;
use Docuccino\Laravel\Registry\ExtensionRegistry;

/**
 * The error-response chain order is deterministic and load-bearing (design §6, first supports()
 * wins): inferred handler (the app's real shapes) → Problem Details preset → framework-default
 * shapes → terminal fallback. Resolved through the real {@see ExtensionRegistry} so registration
 * order cannot perturb it.
 */
it('resolves the exception mapper chain in the documented tier order', function (): void {
    $resolved = app(ExtensionRegistry::class)->resolve(app(), DefaultExtensions::all(), []);

    $order = array_map(static fn (object $mapper): string => $mapper::class, $resolved->exceptionToResponse);

    expect($order)->toBe([
        InferredHandlerExceptionToResponse::class,
        ProblemDetailsExceptionToResponse::class,
        FrameworkErrorsExceptionToResponse::class,
        DefaultExceptionToResponse::class,
    ]);
});
