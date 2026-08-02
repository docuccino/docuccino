<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Extensions;

use Docuccino\Attributes\DeprecatedOperation;
use Docuccino\Attributes\DescriptionFromFile;
use Docuccino\Attributes\Group;
use Docuccino\Attributes\Internal;
use Docuccino\Attributes\OperationId;
use Docuccino\Core\Draft\OperationDraft;
use Docuccino\Core\Extensions\Context\RouteContext;
use Docuccino\Core\Extensions\Contracts\OperationExtension;
use Docuccino\Core\Extensions\Contracts\OperationPhase;
use Docuccino\Core\Patch\Contribution;

/**
 * The overrides layer: docblock summary/description (docblock precedence), then the operation
 * attributes (attribute precedence) — `#[OperationId]` over the default route-name strategy,
 * `#[Group]` → tags, `#[DeprecatedOperation]`, `#[Internal]` → `x-internal`, and
 * `#[DescriptionFromFile]` loading a markdown file into the description.
 */
final class AttributeOverridesExtension implements OperationExtension
{
    public function __construct(
        private readonly string $basePath,
    ) {}

    public function phase(): OperationPhase
    {
        return OperationPhase::Overrides;
    }

    public function handle(OperationDraft $operation, RouteContext $context): void
    {
        // Docblock layer.
        $operation->setSummary($context->summary, Contribution::docblock());
        $operation->setDescription($context->description, Contribution::docblock());

        // Default operationId (route-name strategy) at fallback precedence, so #[OperationId] wins.
        $operation->setOperationId($context->route->name, Contribution::fallback());

        $attribute = Contribution::attribute($context->actionSource());

        $operationId = $context->attributes->first(OperationId::class);
        if ($operationId !== null) {
            $operation->setOperationId($operationId->id, $attribute);
        }

        $tags = $this->tags($context);
        if ($tags !== []) {
            $operation->setTags($tags, $attribute);
        }

        if ($context->attributes->has(DeprecatedOperation::class)) {
            $operation->setDeprecated(true, $attribute);
        }

        if ($context->attributes->has(Internal::class)) {
            $operation->set('x-internal', true, $attribute);
        }

        $fromFile = $context->attributes->first(DescriptionFromFile::class);
        if ($fromFile !== null) {
            $contents = @file_get_contents($this->basePath.'/'.ltrim($fromFile->path, '/'));
            if ($contents !== false) {
                $operation->setDescription(rtrim($contents, "\n"), $attribute);
            }
        }
    }

    /**
     * @return list<string>
     */
    private function tags(RouteContext $context): array
    {
        $tags = [];
        foreach ($context->attributes->all(Group::class) as $group) {
            $mapped = $context->document->mapTag($group->name);
            if (! in_array($mapped, $tags, true)) {
                $tags[] = $mapped;
            }
        }

        return $tags;
    }
}
