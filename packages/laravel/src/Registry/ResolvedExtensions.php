<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Registry;

use Docuccino\Core\Extensions\Contracts\DocumentTransformer;
use Docuccino\Core\Extensions\Contracts\ExceptionToResponse;
use Docuccino\Core\Extensions\Contracts\OperationExtension;
use Docuccino\Core\Extensions\Contracts\OperationPhase;
use Docuccino\Core\Extensions\Contracts\RouteResolver;
use Docuccino\Core\Extensions\Contracts\TypeToSchema;
use Docuccino\Core\Extensions\Ordering\ExtensionSorter;

/**
 * The extension set for one build, partitioned by contract and pre-sorted within each partition
 * by {@see ExtensionSorter}. One instance may satisfy several
 * contracts and appears in each matching partition.
 */
final readonly class ResolvedExtensions
{
    /**
     * @param  list<RouteResolver>  $routeResolvers
     * @param  list<OperationExtension>  $operationExtensions  globally sorted; group by phase downstream
     * @param  list<TypeToSchema>  $typeToSchema
     * @param  list<ExceptionToResponse>  $exceptionToResponse
     * @param  list<DocumentTransformer>  $documentTransformers
     */
    public function __construct(
        public array $routeResolvers = [],
        public array $operationExtensions = [],
        public array $typeToSchema = [],
        public array $exceptionToResponse = [],
        public array $documentTransformers = [],
    ) {}

    /**
     * The operation extensions declaring the given phase, in sorted order.
     *
     * @return list<OperationExtension>
     */
    public function operationExtensionsFor(OperationPhase $phase): array
    {
        return array_values(array_filter(
            $this->operationExtensions,
            static fn (OperationExtension $extension): bool => $extension->phase() === $phase,
        ));
    }

    /**
     * The deduped class-string list of every resolved extension, in a deterministic order — a
     * fragment-cache key input (design §10): a changed extension set must invalidate every fragment.
     *
     * @return list<string>
     */
    public function classSignature(): array
    {
        $classes = [];
        foreach ([$this->routeResolvers, $this->operationExtensions, $this->typeToSchema, $this->exceptionToResponse, $this->documentTransformers] as $partition) {
            foreach ($partition as $extension) {
                $classes[$extension::class] = true;
            }
        }

        $names = array_keys($classes);
        sort($names);

        return $names;
    }
}
