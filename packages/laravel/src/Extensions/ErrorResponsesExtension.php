<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Extensions;

use Docuccino\Core\Draft\OperationDraft;
use Docuccino\Core\Extensions\Context\RouteContext;
use Docuccino\Core\Extensions\Contracts\ExceptionToResponse;
use Docuccino\Core\Extensions\Contracts\OperationExtension;
use Docuccino\Core\Extensions\Contracts\OperationPhase;
use Docuccino\Core\Extensions\Validation\ResponseDraftApplier;
use Docuccino\Core\Inference\ThrowDisposition;

/**
 * Turns the action's signalled exceptions into error responses (design §Errors) by running each
 * through the resolved {@see ExceptionToResponse} chain
 * (first supports() + non-null wins) and merging the result into the operation via the shared
 * {@see ResponseDraftApplier}. Skipped when the document sets `error_responses => 'none'`.
 */
final class ErrorResponsesExtension implements OperationExtension
{
    public function __construct(
        private readonly ResponseDraftApplier $applier = new ResponseDraftApplier,
    ) {}

    public function phase(): OperationPhase
    {
        return OperationPhase::Errors;
    }

    public function handle(OperationDraft $operation, RouteContext $context): void
    {
        if ($context->document->errorResponses === 'none') {
            return;
        }

        foreach ($context->analysis()->throws as $throw) {
            if ($throw->disposition !== ThrowDisposition::Signal) {
                continue;
            }

            foreach ($context->exceptionMappers as $mapper) {
                if (! $mapper->supports($throw, $context)) {
                    continue;
                }

                $draft = $mapper->toResponse($throw, $context, $context->components);
                if ($draft === null) {
                    continue;
                }

                $this->applier->apply($operation, $draft, $mapper->producer());
                break;
            }
        }
    }
}
