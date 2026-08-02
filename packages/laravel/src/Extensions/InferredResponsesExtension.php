<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Extensions;

use Docuccino\Core\Draft\OperationDraft;
use Docuccino\Core\Extensions\Context\RouteContext;
use Docuccino\Core\Extensions\Contracts\OperationExtension;
use Docuccino\Core\Extensions\Contracts\OperationPhase;
use Docuccino\Core\Extensions\Ordering\ExtensionOrder;
use Docuccino\Core\Extensions\Ordering\Priorities;
use Docuccino\Core\Inference\DType\DType;
use Docuccino\Core\Inference\DType\NeverT;
use Docuccino\Core\Inference\DType\UnionT;
use Docuccino\Core\Inference\DType\VoidT;
use Docuccino\Core\Patch\Contribution;

/**
 * Infers the success response from the action's return paths (design §5): every non-void return
 * type is unioned and converted through the type→schema chain into the `200` response body. HTTP
 * status inference (204/201/…) and content-type variety land in Phase 4; Phase 3a maps every
 * inferred return to `200 application/json`.
 */
#[ExtensionOrder(priority: Priorities::EARLY)]
final class InferredResponsesExtension implements OperationExtension
{
    public function phase(): OperationPhase
    {
        return OperationPhase::Responses;
    }

    public function handle(OperationDraft $operation, RouteContext $context): void
    {
        $types = [];
        $location = null;
        foreach ($context->analysis()->returns as $return) {
            if ($return->type instanceof VoidT || $return->type instanceof NeverT) {
                continue;
            }
            $location ??= $return->location;
            $types[] = $return->type;
        }

        if ($types === []) {
            return;
        }

        $type = count($types) === 1 ? $types[0] : UnionT::of($this->dedupe($types));
        $result = $context->converter()->toSchema($type);

        // Anchor the inferred body to the first contributing return path (design §4); an engine that
        // reports no usable location yields a sourceless contribution rather than a churny one.
        $source = $location !== null ? $context->sourceAt($location) : null;
        $contribution = Contribution::inference($source, $result->confidence);

        $response = $operation->response('200');
        $response->setDescription('OK', Contribution::fallback());

        foreach ($result->schema as $keyword => $value) {
            $response->content('application/json')->set($keyword, $value, $contribution);
        }
    }

    /**
     * @param  list<DType>  $types
     * @return list<DType>
     */
    private function dedupe(array $types): array
    {
        $byKey = [];
        foreach ($types as $type) {
            $byKey[$type->canonicalKey()] = $type;
        }

        return array_values($byKey);
    }
}
