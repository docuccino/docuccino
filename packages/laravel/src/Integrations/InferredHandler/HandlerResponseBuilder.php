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

/**
 * Turns a handler/closure analysis into an error response (design §6). Reads the recovered
 * `JsonResponse<TPayload, TStatus>` — the handler's REAL rendered status + payload shape — and
 * builds a {@see ResponseDraft} under that status, hoisting the payload schema through the route's
 * converter. Returns null when the analysis recovered no documentable `JsonResponse` (too-dynamic
 * body → the mapper defers to the next tier + a diagnostic).
 */
final class HandlerResponseBuilder
{
    private const JSON_RESPONSE = 'Illuminate\\Http\\JsonResponse';

    private const REASONS = [
        '400' => 'Bad Request',
        '401' => 'Unauthenticated',
        '403' => 'Forbidden',
        '404' => 'Not Found',
        '409' => 'Conflict',
        '422' => 'Unprocessable Entity',
        '429' => 'Too Many Requests',
        '500' => 'Server Error',
    ];

    public static function build(ActionAnalysis $analysis, RouteContext $context, Contribution $contribution): ?ResponseDraft
    {
        foreach ($analysis->returns as $return) {
            $type = $return->type;
            if (! $type instanceof ClassT || $type->fqcn !== self::JSON_RESPONSE) {
                continue;
            }

            $status = self::foldStatus($type->typeArgs[1] ?? null);
            $draft = new ResponseDraft($status);
            $draft->setDescription(self::REASONS[$status] ?? 'Error', $contribution);

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
