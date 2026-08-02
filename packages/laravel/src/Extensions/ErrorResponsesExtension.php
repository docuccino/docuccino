<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Extensions;

use Docuccino\Core\Draft\OperationDraft;
use Docuccino\Core\Draft\ResponseDraft;
use Docuccino\Core\Extensions\Context\RouteContext;
use Docuccino\Core\Extensions\Contracts\ExceptionToResponse;
use Docuccino\Core\Extensions\Contracts\OperationExtension;
use Docuccino\Core\Extensions\Contracts\OperationPhase;
use Docuccino\Core\Inference\ThrowDisposition;
use Docuccino\Core\Patch\Contribution;

/**
 * Turns the action's signalled exceptions into error responses (design §Errors) by running each
 * through the resolved {@see ExceptionToResponse} chain
 * (first supports() + non-null wins) and merging the result into the operation. Skipped when the
 * document sets `error_responses => 'none'`.
 */
final class ErrorResponsesExtension implements OperationExtension
{
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

                $this->merge($operation, $draft, $mapper->producer());
                break;
            }
        }
    }

    private function merge(OperationDraft $operation, ResponseDraft $draft, string $producer): void
    {
        $frozen = $draft->freeze();
        $response = $operation->response($draft->status);
        $contribution = Contribution::forProducer($producer);

        if ($frozen->description !== null) {
            $response->setDescription($frozen->description, $contribution);
        }

        foreach ($frozen->content ?? [] as $mediaType => $media) {
            $schema = is_array($media) && is_array($media['schema'] ?? null) ? $media['schema'] : [];
            foreach ($schema as $keyword => $value) {
                // The mapper's own draft froze in its provenance under x-docuccino; drop it so the
                // target schema keeps only its keywords (the merge records fresh provenance).
                if ($keyword === 'x-docuccino') {
                    continue;
                }
                $response->content($mediaType)->set($keyword, $value, $contribution);
            }
        }
    }
}
