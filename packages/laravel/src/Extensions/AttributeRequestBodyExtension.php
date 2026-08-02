<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Extensions;

use Docuccino\Attributes\BodyParameter;
use Docuccino\Core\Draft\OperationDraft;
use Docuccino\Core\Extensions\Context\RouteContext;
use Docuccino\Core\Extensions\Contracts\OperationExtension;
use Docuccino\Core\Extensions\Contracts\OperationPhase;
use Docuccino\Core\Patch\Contribution;
use Docuccino\Laravel\Types\TypeStringParser;

/**
 * Assembles a JSON request body from `#[BodyParameter]` attributes (design §Attribute set — each
 * patches one body property). Phase 3a has no inferred request body, so the attributes are the
 * sole source; inferred FormRequest/validation bodies land in Phase 4.
 */
final class AttributeRequestBodyExtension implements OperationExtension
{
    public function __construct(
        private readonly TypeStringParser $types = new TypeStringParser,
    ) {}

    public function phase(): OperationPhase
    {
        return OperationPhase::Request;
    }

    public function handle(OperationDraft $operation, RouteContext $context): void
    {
        $bodyParameters = $context->attributes->all(BodyParameter::class);
        if ($bodyParameters === []) {
            return;
        }

        $properties = [];
        $required = [];
        foreach ($bodyParameters as $attribute) {
            $schema = $attribute->type !== null
                ? $context->converter()->toSchema($this->types->parse($attribute->type))->schema
                : ['type' => 'string'];

            if ($attribute->description !== null) {
                $schema['description'] = $attribute->description;
            }

            $properties[$attribute->name] = $schema;
            if ($attribute->required) {
                $required[] = $attribute->name;
            }
        }

        $schema = ['type' => 'object', 'properties' => $properties];
        if ($required !== []) {
            $schema['required'] = $required;
        }

        $operation->set('requestBody', [
            'content' => ['application/json' => ['schema' => $schema]],
        ], Contribution::attribute());
    }
}
