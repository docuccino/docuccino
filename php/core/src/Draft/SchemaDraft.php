<?php

declare(strict_types=1);

namespace Docuccino\Core\Draft;

use Docuccino\Core\Document\NodeExtension;
use Docuccino\Core\Document\SchemaObject;
use Docuccino\Core\Extensions\Validation\DeepObjectMembers;
use Docuccino\Core\Patch\Contribution;
use Docuccino\Core\Patch\PatchGuard;
use Docuccino\Core\Patch\PatchResult;
use Docuccino\Core\Patch\Remove;

/**
 * A mutable JSON Schema builder. Scalar keywords (type, format, enum, required, …) go through the
 * guard; nested object properties merge by name, so a later layer can patch a single property without
 * discarding inferred siblings.
 */
final class SchemaDraft
{
    private readonly PatchGuard $guard;

    /**
     * @var array<string, SchemaDraft>
     */
    private array $properties = [];

    /**
     * Member names a subtraction took off this schema ({@see removeProperty()}).
     *
     * @var list<string>
     */
    private array $removed = [];

    private ?string $id = null;

    /**
     * @var array<string, mixed>|null
     */
    private ?array $mock = null;

    public function __construct()
    {
        $this->guard = new PatchGuard;
    }

    public function set(string $keyword, mixed $value, Contribution $by): PatchResult
    {
        return $this->guard->apply($keyword, $value, $by);
    }

    /**
     * Write a whole schema as ONE declared shape: the keywords it states are applied, and the ones it
     * leaves out are retracted wherever it outranks them. Use this for a converted type — a
     * `#[Response(type: …)]` body, a parameter's declared type, an envelope a trace worked out — and
     * {@see set()} for patching a single keyword.
     *
     * The rule, stated once: **a declaration states its shape whole.** Keywords compose as a
     * conjunction, so a superseded one left standing publishes something nobody declared — a map
     * inference's `additionalProperties` beside a declared closed shape says extra keys are allowed,
     * an inferred `type`/`items` beside a declared `$ref` says the body must satisfy both. Which
     * keywords a shape supersedes is {@see SchemaKeywords}: the ones describing the value's shape go
     * unless restated, the ones refining a type go once the declared shape is not that type, and
     * annotations — a description, an authored example — stay, because they were never about the
     * shape. A write that states no shape at all supersedes nothing.
     *
     * Retraction is a guarded write like any other, so it is bounded by precedence: an overlay-stated
     * keyword survives an attribute-declared shape, and an equal layer can only shadow.
     *
     * @param  array<string, mixed>  $schema
     */
    public function declareShape(array $schema, Contribution $by): void
    {
        if (SchemaKeywords::statesShape($schema)) {
            foreach (array_keys($this->guard->resolved()) as $keyword) {
                if (SchemaKeywords::isSuperseded((string) $keyword, $schema)) {
                    $this->guard->apply((string) $keyword, Remove::value(), $by);
                }
            }

            // Nested property drafts are the other half of `properties`, and freeze() publishes them
            // over the keyword — so a shape that supersedes the keyword has to take them with it, or
            // the declared body would lose to the properties it replaced.
            foreach ($this->properties as $name => $property) {
                if ($property->isSupersededBy($by)) {
                    unset($this->properties[$name]);
                }
            }
        }

        foreach ($schema as $keyword => $value) {
            $this->guard->apply((string) $keyword, $value, $by);
        }
    }

    public function property(string $name): self
    {
        return $this->properties[$name] ??= new self;
    }

    public function hasProperty(string $name): bool
    {
        return isset($this->properties[$name]);
    }

    /**
     * The member names this schema will publish, in the order {@see freeze()} publishes them: the nested
     * property drafts where there are any, and otherwise the keys of a `properties` written whole as a
     * keyword. One reading rather than two, because whoever asks whether a name is a member of this
     * object and `freeze()` deciding what the object says about that name have to agree — a reader that
     * saw only the drafts would answer "no member" for a declared shape and leave a subtraction with
     * nothing to take away.
     *
     * @internal Core-only. A name is a member of a CONTAINER and this class cannot see which parameter
     * it belongs to, so an extension asks {@see DeepObjectMembers}
     * instead.
     *
     * @return list<string>
     */
    public function propertyNames(): array
    {
        $resolved = $this->resolvedField('properties');
        $names = $this->properties !== []
            ? array_keys($this->properties)
            : array_keys(is_array($resolved) ? $resolved : []);

        return array_values(array_diff(array_map(strval(...), $names), $this->removed));
    }

    /**
     * Take one member off this schema: it is not published, and the `required` list does not name it.
     * Answers whether the schema published the name, which is the caller's evidence that a subtraction
     * reached something — a member that was never there and one that was dropped leave the same object.
     *
     * **A subtraction is not a contribution.** It is applied at {@see freeze()} rather than written
     * through the guard, so nothing outranks it — the unconditional reading
     * {@see OperationDraft::removeParameter()} already makes of a whole parameter, for the same reason:
     * an author's "do not publish this" that a later layer could quietly overrule leaves the very field
     * they marked as not-for-publication in the document.
     *
     * The `required` list travels with it because that list belongs to the PARENT rather than to the
     * member, so this is the only place both are in view. One naming a member nobody publishes tells a
     * consumer their request must carry a value the document does not describe, and a generated client
     * then demands a field it cannot name.
     *
     * @internal Core-only — see {@see propertyNames()}.
     */
    public function removeProperty(string $name): bool
    {
        $published = in_array($name, $this->propertyNames(), true);

        unset($this->properties[$name]);

        if (! in_array($name, $this->removed, true)) {
            $this->removed[] = $name;
        }

        return $published;
    }

    /**
     * @internal Not part of the frozen extension-author surface — an identity is a function of the
     * assembled document and is stamped on the frozen node, so nothing an extension sees decides one.
     */
    public function assignId(?string $id): self
    {
        $this->id = $id;

        return $this;
    }

    /**
     * @param  array<string, mixed>|null  $mock
     */
    public function assignMock(?array $mock): self
    {
        $this->mock = $mock;

        return $this;
    }

    /**
     * Take over another schema's keywords and properties, each at the contribution that wrote it — the
     * nested half of {@see ResponseDraft::absorb()}.
     *
     * @internal Core-only; extensions build drafts rather than move them about.
     */
    public function absorb(self $other): void
    {
        foreach ($other->guard->contributions() as $keyword => $write) {
            $this->guard->apply($keyword, $write['value'], $write['by']);
        }

        foreach ($other->properties as $name => $property) {
            $this->property((string) $name)->absorb($property);
        }
    }

    /** The provenance producer of the currently-winning contribution for a field, or null if unset. */
    public function producerFor(string $field): ?string
    {
        return $this->guard->producerFor($field);
    }

    /** The currently-resolved value of a field (Remove sentinels omitted), or null if unset. */
    public function resolvedField(string $field): mixed
    {
        return $this->guard->resolved()[$field] ?? null;
    }

    /**
     * Whether this draft, as it now stands, says nothing about the value it describes: every keyword on it
     * is an annotation ({@see SchemaKeywords::saysNothingAboutTheInstance()}) and no property was written
     * under it. What a producer reads when its claim is about the OUTCOME rather than its own
     * contribution. A keyword this model cannot classify counts as saying something.
     */
    public function saysNothingAboutTheInstance(): bool
    {
        if ($this->properties !== []) {
            return false;
        }

        foreach (array_keys($this->guard->resolved()) as $keyword) {
            if (! SchemaKeywords::saysNothingAboutTheInstance((string) $keyword)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Whether a contribution outranks every keyword written here and in every nested property, so it
     * speaks over the schema as a whole — the nested half of {@see ResponseDraft::isSupersededBy()}.
     *
     * @internal Core-only; the retraction paths ask this, extensions patch keywords.
     */
    public function isSupersededBy(Contribution $by): bool
    {
        if (! $this->guard->outranksAll($by)) {
            return false;
        }

        foreach ($this->properties as $property) {
            if (! $property->isSupersededBy($by)) {
                return false;
            }
        }

        return true;
    }

    /**
     * @internal Not part of the frozen extension-author surface — it hands back the (also
     * `@internal`) {@see PatchGuard}. Extensions read winning state via {@see producerFor()} /
     * {@see resolvedField()}.
     */
    public function guard(): PatchGuard
    {
        return $this->guard;
    }

    /**
     * @internal Not part of the frozen extension-author surface — it hands back the (also
     * `@internal`) {@see SchemaObject} document model. Extensions hand drafts back to the pipeline,
     * which freezes them.
     */
    public function freeze(): SchemaObject
    {
        $data = $this->guard->resolved();

        if ($this->properties !== []) {
            $properties = [];
            foreach ($this->properties as $name => $draft) {
                $properties[$name] = $draft->freeze()->toArray();
            }
            $data['properties'] = $properties;
        }

        if ($this->removed !== []) {
            $data = self::without($data, $this->removed);
        }

        $docuccino = new NodeExtension(
            id: $this->id,
            provenance: $this->guard->provenance(),
            mock: $this->mock,
        );

        if (! $docuccino->isEmpty()) {
            $data['x-docuccino'] = $docuccino->toArray();
        }

        return new SchemaObject($data);
    }

    /**
     * `$data` with every subtracted member gone from `properties` and from `required`, each keyword
     * omitted once nothing is left in it: an empty `required` states nothing, and every producer of that
     * list already omits it rather than publishing the shape.
     *
     * @param  array<string, mixed>  $data
     * @param  list<string>  $removed
     * @return array<string, mixed>
     */
    private static function without(array $data, array $removed): array
    {
        $properties = $data['properties'] ?? null;
        if (is_array($properties)) {
            foreach ($removed as $name) {
                unset($properties[$name]);
            }

            $data['properties'] = $properties;
            if ($properties === []) {
                unset($data['properties']);
            }
        }

        $required = $data['required'] ?? null;
        if (is_array($required)) {
            $kept = array_values(array_filter(
                $required,
                static fn (mixed $each): bool => ! in_array($each, $removed, true),
            ));

            $data['required'] = $kept;
            if ($kept === []) {
                unset($data['required']);
            }
        }

        return $data;
    }
}
