<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Extensions;

use Docuccino\Attributes\CookieParameter;
use Docuccino\Attributes\HeaderParameter;
use Docuccino\Attributes\IgnoreParam;
use Docuccino\Attributes\PathParameter;
use Docuccino\Attributes\QueryParameter;
use Docuccino\Core\Draft\OperationDraft;
use Docuccino\Core\Draft\ParameterDraft;
use Docuccino\Core\Extensions\Context\RouteContext;
use Docuccino\Core\Extensions\Contracts\OperationExtension;
use Docuccino\Core\Extensions\Contracts\OperationPhase;
use Docuccino\Core\Patch\Contribution;
use Docuccino\Inference\PhpStan\Types\TypeStringParser;

/**
 * Applies the parameter attributes as the attribute precedence layer (design §7): query, header,
 * cookie and explicit path parameters, plus `#[IgnoreParam]` removals. Type strings are parsed to
 * a DType and converted through the route's schema chain, so `#[QueryParameter(type: 'int')]`
 * yields an integer schema.
 */
final class AttributeParametersExtension implements OperationExtension
{
    public function __construct(
        private readonly TypeStringParser $types = new TypeStringParser,
    ) {}

    public function phase(): OperationPhase
    {
        return OperationPhase::Parameters;
    }

    public function handle(OperationDraft $operation, RouteContext $context): void
    {
        foreach ($context->attributes->all(IgnoreParam::class) as $ignore) {
            $this->remove($operation, $ignore->name, $ignore->in);
        }

        foreach ($context->attributes->all(QueryParameter::class) as $attribute) {
            $parameter = $operation->parameter('query', $attribute->name);
            $this->apply($parameter, $context, $attribute->type, $attribute->description, $attribute->required, $attribute->default, $attribute->example);
        }

        foreach ($context->attributes->all(HeaderParameter::class) as $attribute) {
            $parameter = $operation->parameter('header', $attribute->name);
            $this->apply($parameter, $context, $attribute->type, $attribute->description, $attribute->required, null, $attribute->example);
        }

        foreach ($context->attributes->all(CookieParameter::class) as $attribute) {
            $parameter = $operation->parameter('cookie', $attribute->name);
            $this->apply($parameter, $context, $attribute->type, $attribute->description, $attribute->required, null, $attribute->example);
        }

        foreach ($context->attributes->all(PathParameter::class) as $attribute) {
            $parameter = $operation->parameter('path', $attribute->name);
            $this->apply($parameter, $context, $attribute->type, $attribute->description, true, null, $attribute->example);
            if ($attribute->format !== null) {
                $parameter->schema()->set('format', $attribute->format, Contribution::attribute($context->actionSource()));
            }
        }
    }

    private function apply(
        ParameterDraft $parameter,
        RouteContext $context,
        ?string $type,
        ?string $description,
        bool $required,
        mixed $default,
        mixed $example,
    ): void {
        $contribution = Contribution::attribute($context->actionSource());

        $parameter->setRequired($required, $contribution);
        $parameter->setDescription($description, $contribution);

        if ($type !== null) {
            foreach ($context->converter()->toSchema($this->types->parse($type))->schema as $keyword => $value) {
                $parameter->schema()->set($keyword, $value, $contribution);
            }
        }

        if ($default !== null) {
            $parameter->schema()->set('default', $default, $contribution);
        }

        if ($example !== null) {
            $parameter->set('example', $example, $contribution);
        }
    }

    private function remove(OperationDraft $operation, string $name, ?string $in): void
    {
        foreach ($in === null ? ['query', 'path', 'header', 'cookie'] : [$in] as $location) {
            $operation->removeParameter($location, $name);
        }
    }
}
