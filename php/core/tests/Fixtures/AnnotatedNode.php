<?php

declare(strict_types=1);

namespace Docuccino\Core\Tests\Fixtures;

use Docuccino\Attributes\Description;
use Docuccino\Attributes\Example;
use Docuccino\Attributes\Summary;
use Docuccino\Core\Extensions\BuiltIn\ClassTypeToSchema;

/**
 * A plain PHP DTO carrying every property-target shape of `#[Description]`, `#[Example]` and
 * `#[Summary]` at once — the two that publish and every one a property schema cannot hold — so the
 * fallback {@see ClassTypeToSchema} is the only mapper that can read them.
 *
 * Every declaration sits on a promoted constructor property, which is where an author writes one and
 * where PHP hangs the attribute off the parameter as well as the property.
 */
final readonly class AnnotatedNode
{
    public function __construct(
        #[Description(text: 'Who owns the invoice.')]
        #[Example('acme-corp')]
        public string $tenant,
        // An attribute argument is a real PHP value, so a boolean example stays a boolean — the one
        // thing `@example false` in a docblock cannot promise.
        #[Example(value: false)]
        public bool $settled,
        // Prose the engine already recovered from the docblock, overwritten at attribute precedence.
        #[Description(text: 'What a consumer needs to know.')]
        public string $documented,
        // A schema property has a description and no summary.
        #[Summary('A slug')]
        public string $summarised,
        // No application root reaches a schema mapper, so `file:` says nothing here.
        #[Description(file: 'docs/tenant.md')]
        public string $filed,
        #[Description]
        public string $undescribed,
        #[Description(text: 'inline', file: 'docs/tenant.md')]
        public string $overdescribed,
        #[Example]
        public string $valueless,
        #[Example(value: 'a', externalValue: 'https://example.test/a.json')]
        public string $twoSourced,
        // Everything an Example Object holds and a bare `example` slot does not.
        #[Example(name: 'first', summary: 'The first one', value: 'a', status: 200)]
        public string $named,
        // Two usable declarations, one slot: the first stands, as it does on an operation.
        #[Example(value: 'first')]
        #[Example(value: 'second')]
        public string $twice,
        // Never published, so nothing here is read and nothing is reported.
        #[Description(text: 'Prose for a member the schema hides.')]
        #[Summary('Also unread')]
        public string $unpublished,
    ) {}
}
