<?php

declare(strict_types=1);

namespace Docuccino\Core\Contract;

/**
 * What one documented parameter gives a reader to hold its values to: which of the three answers
 * ({@see ParameterSchemaKind}) the contract made, and — where it published a schema — the node whose
 * keywords a wire value is read back against.
 *
 * ONE answer, rather than a nullable schema beside a boolean that says whether the null meant anything.
 * `schema` absent, `schema` holding something no validator can take, and a parameter documented with
 * `content` instead are three different facts about the document; a reader that answers all three with
 * null cannot tell a check nobody can perform from a document that says nothing, and those are
 * different sentences for whoever reads the note. The boolean that told them apart was opt-in, so
 * forgetting it degraded the check in silence — a `kind` cannot be handed to a validator by mistake,
 * and a `match` over it that grows an arm and forgets one is a build failure instead.
 */
final readonly class ParameterSchema
{
    /**
     * @param  array<string, mixed>|null  $node  the schema's own keywords, for {@see ParameterValue} to
     *                                           read a wire string back against — null wherever there
     *                                           are none, which a boolean schema and `{}` both are. The
     *                                           validator is handed the schema by POINTER
     *                                           ({@see ContractParameter::schemaSegments()}), so a
     *                                           boolean is still checked; this is only the reading.
     */
    private function __construct(
        public ParameterSchemaKind $kind,
        public ?array $node,
    ) {}

    /**
     * Read off a parameter object — or a response header object, which OAS defines as one — with its
     * `$ref` already followed.
     *
     * The member being PRESENT is not the question, on either side: a `schema` no validator can take is
     * exactly as uncheckable as one nobody wrote, and a `content` that is not a map of media types is
     * not the content object whose name the note would use. `[]` counts as a schema, because that is
     * how associative decoding spells `{}`.
     *
     * @param  array<string, mixed>  $definition
     */
    public static function of(array $definition): self
    {
        $schema = $definition['schema'] ?? null;

        if (is_array($schema)) {
            /** @var array<string, mixed> $schema */
            return new self(ParameterSchemaKind::Checkable, $schema);
        }

        if (is_bool($schema)) {
            return new self(ParameterSchemaKind::Checkable, null);
        }

        return new self(
            is_array($definition['content'] ?? null) ? ParameterSchemaKind::Content : ParameterSchemaKind::Absent,
            null,
        );
    }
}
