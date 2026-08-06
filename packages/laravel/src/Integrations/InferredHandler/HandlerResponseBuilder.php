<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Integrations\InferredHandler;

use Docuccino\Core\Draft\ResponseDraft;
use Docuccino\Core\Extensions\Context\RouteContext;
use Docuccino\Core\Inference\ActionAnalysis;
use Docuccino\Core\Inference\DType\ClassT;
use Docuccino\Core\Inference\DType\LiteralT;
use Docuccino\Core\Inference\DType\NeverT;
use Docuccino\Core\Inference\DType\VoidT;
use Docuccino\Core\Patch\Contribution;
use Docuccino\Laravel\Integrations\Support\FrameworkClasses;
use Docuccino\Laravel\Integrations\Support\FrameworkExceptionTable;

/**
 * Turns a handler/closure analysis into an error response (design §6). Reads the recovered
 * `JsonResponse<TPayload, TStatus>` — the handler's REAL rendered status + payload shape — and
 * builds a {@see ResponseDraft} under that status, hoisting the payload schema through the route's
 * converter. Returns null when the analysis recovered no documentable `JsonResponse` (too-dynamic
 * body → the mapper defers to the next tier + a diagnostic). Reason phrases come from the shared
 * {@see FrameworkExceptionTable} so the inferred-handler tier can never drift from the others.
 */
final class HandlerResponseBuilder
{
    public static function build(ActionAnalysis $analysis, RouteContext $context, Contribution $contribution): ?ResponseDraft
    {
        foreach ($analysis->returns as $return) {
            $type = $return->type;
            if (! $type instanceof ClassT || $type->fqcn !== FrameworkClasses::JSON_RESPONSE) {
                continue;
            }

            $status = self::foldStatus($type->typeArgs[1] ?? null);
            $draft = new ResponseDraft($status);
            $draft->setDescription(FrameworkExceptionTable::reason($status), $contribution);

            $payload = $type->typeArgs[0] ?? null;
            if ($payload !== null && ! $payload instanceof VoidT && ! $payload instanceof NeverT) {
                foreach ($context->converter()->toSchema($payload)->schema as $keyword => $value) {
                    $draft->content('application/json')->set($keyword, $value, $contribution);
                }
            }

            return $draft;
        }

        return null;
    }

    private static function foldStatus(mixed $statusArg): string
    {
        return $statusArg instanceof LiteralT && is_int($statusArg->value) ? (string) $statusArg->value : '200';
    }
}
