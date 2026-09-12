<?php

declare(strict_types=1);

namespace Docuccino\Core\Extensions\Validation;

use Docuccino\Core\Draft\OperationDraft;
use Docuccino\Core\Draft\ParameterDraft;
use Docuccino\Core\Draft\SchemaDraft;
use Docuccino\Core\Extensions\Contracts\OperationPhase;
use Docuccino\Core\Patch\Contribution;
use Docuccino\Core\Patch\Remove;

/**
 * Where a bracketed query name lands on an operation that already publishes the container it names as a
 * deepObject. `filter[min_days]` is the wire spelling of the `min_days` member of a `filter` object, so
 * under that representation the container IS the parameter: a flat parameter beside it would describe
 * the same bytes twice under two identities, which a consumer reads as two inputs and a generated
 * client may send both of. With no such container the bracketed name is the parameter and nothing here
 * applies — so this is inert under the bracketed representation rather than a second grammar for it.
 *
 * Names are read through {@see FieldPath::fromQueryName()}, the declared inverse of the write that
 * produces them, so every depth the bracketed writer spells is a depth this recognises.
 *
 * Additive and subtractive declarations read the SAME name here — {@see schemaFor()} patches a member,
 * {@see Remove()} takes one off — so the two representations answer a bracketed declaration alike. A
 * subtraction that could not reach a member would be the worst of the two to get wrong: it leaves
 * exactly the document a working one leaves, so the author goes on believing the field is hidden.
 *
 * The answer is a function of what the operation publishes rather than of registration order: every
 * producer of a deepObject container writes in {@see OperationPhase::Parameters}, at a priority ahead of
 * the parameter attributes, and every producer of a recovered rule set writes a whole phase later in
 * {@see OperationPhase::Request}. So a container that exists is there to be found, and one that does not
 * is never going to be.
 *
 * Requiredness is the one fact with no home on the member's own schema — it belongs to the list its
 * PARENT keeps — so it is accumulated here and written once per parent by {@see flush()}: a second
 * equal-layer write to one `required` list would shadow it rather than append. That a required member
 * makes the container itself required is the container's own reading, made where it freezes
 * ({@see ParameterDraft::freeze()}).
 */
final class DeepObjectMembers
{
    /**
     * Per parent schema: the schema whose `required` list names the members, and what each member's
     * requiredness was stated to be.
     *
     * @var array<string, array{0: SchemaDraft, 1: array<string, bool>}>
     */
    private array $stated = [];

    public function __construct(
        private readonly OperationDraft $operation,
    ) {}

    /**
     * The schema draft a bracketed query name patches, created if the container does not publish that
     * member yet — a key the container's own producer never enumerated is still a key this producer
     * knows the server takes. Null means no deepObject container claims the name, so the name is the
     * parameter.
     */
    public function schemaFor(string $name): ?SchemaDraft
    {
        $resolved = $this->resolve($name);

        return $resolved === null ? null : $resolved[0]->property($resolved[1]);
    }

    /**
     * Record what a producer states about one member's requiredness. `null` is the absent statement and
     * is not one; `false` is, and takes a member off the list. A name no container claims is not a
     * member, so nothing is recorded for it.
     */
    public function stateRequired(string $name, ?bool $required): void
    {
        $resolved = $this->resolve($name);
        if ($resolved === null || $required === null) {
            return;
        }

        [$parent, $member, $key] = $resolved;

        $this->stated[$key] ??= [$parent, []];
        $this->stated[$key][1][$member] = $required;
    }

    /** Write each parent's merged `required` list, once, at the layer of the producer that stated it. */
    public function flush(Contribution $by): void
    {
        foreach ($this->stated as [$parent, $members]) {
            $resolved = $parent->resolvedField('required');
            $existing = is_array($resolved) ? array_values(array_filter($resolved, 'is_string')) : [];

            $merged = array_values(array_unique([...$existing, ...array_keys(array_filter($members))]));
            $merged = array_values(array_filter($merged, static fn (string $each): bool => $members[$each] ?? true));

            if ($merged === $existing) {
                continue;
            }

            // Emptied rather than emptied-out: every other producer of a `required` list omits the
            // keyword when it has no members, so a statement that takes the last one off owes the same
            // shape — and only the removal sentinel reaches "absent" through the guard.
            $parent->set('required', $merged === [] ? Remove::value() : $merged, $by);
        }
    }

    /**
     * Take one bracketed member off the container that publishes it — the subtractive half of
     * {@see schemaFor()}, and the only path a declaration naming `filter[opaque]` has to a document
     * whose `opaque` is a MEMBER rather than a parameter of its own. Answers whether a container
     * published the name, which is the caller's evidence that the declaration reached something: a
     * subtraction leaves the same document whether it worked or not.
     *
     * Nothing is minted on the way, which is the one way this differs from the additive read: a name no
     * container publishes has nothing to take away, so walking it must not create the very member it was
     * asked to remove. What removal means for the container's `required` list, and for the container's
     * own requiredness, is {@see SchemaDraft::removeProperty()}.
     */
    public function remove(string $name): bool
    {
        $located = $this->locate($name);

        return $located !== null && $located[0]->removeProperty($located[1]);
    }

    /**
     * Every member every deepObject query container on this operation publishes, under the bracketed
     * name an author writes it as — what a report about a name that matched nothing has to name beside
     * the parameters, or a bracketed typo is answered with the container alone and the member spelling
     * is nowhere for the reader to compare against. It is also what the other representation already
     * answers, where each of these members IS a parameter of its own.
     *
     * Byte-sorted, and read at every depth {@see Remove()} reaches, so the answer is a function of what
     * the operation publishes rather than of the order its producers wrote them.
     *
     * @return list<string>
     */
    public function memberNames(): array
    {
        $names = [];

        foreach ($this->operation->parameterKeys() as $key) {
            [$in, $container] = array_pad(explode(':', $key, 2), 2, '');
            if ($in !== 'query' || $container === '') {
                continue;
            }

            $parameter = $this->operation->parameter('query', $container);
            if (! self::isContainer($parameter)) {
                continue;
            }

            $names = [...$names, ...self::descend($parameter->schema(), [$container])];
        }

        sort($names, SORT_STRING);

        return $names;
    }

    /**
     * The schema whose `required` list would name the member, the member's name, and the parent's own
     * bracketed name as the accumulation key — or null when no deepObject container on this operation
     * claims the bracketed name. The key is that name rather than the draft's object identity, so
     * grouping is a function of the path and not of allocation order.
     *
     * @return array{0: SchemaDraft, 1: string, 2: string}|null
     */
    private function resolve(string $name): ?array
    {
        $located = $this->container($name);
        if ($located === null) {
            return null;
        }

        [$parameter, $container, $segments] = $located;
        $member = (string) array_pop($segments);

        $parent = $parameter->schema();
        foreach ($segments as $segment) {
            $parent = $parent->property($segment);
        }

        return [$parent, $member, FieldPath::toQueryName([$container, ...$segments])];
    }

    /**
     * The same walk without minting anything: the schema that PUBLISHES the member, and the member's
     * name. A depth held as a keyword-written `properties` map rather than as a nested draft is not
     * descended into, which is exactly the depth {@see memberNames()} lists — so a name that is offered
     * as droppable is one this can drop.
     *
     * @return array{0: SchemaDraft, 1: string}|null
     */
    private function locate(string $name): ?array
    {
        $located = $this->container($name);
        if ($located === null) {
            return null;
        }

        [$parameter, , $segments] = $located;
        $member = (string) array_pop($segments);

        $parent = $parameter->schema();
        foreach ($segments as $segment) {
            if (! $parent->hasProperty($segment)) {
                return null;
            }

            $parent = $parent->property($segment);
        }

        return [$parent, $member];
    }

    /**
     * The one reading of "does this bracketed name land in a deepObject container": the container's
     * parameter, its name, and the path below it. Both walks start here, so a name cannot be a member
     * for the producer that writes it and a parameter for the one that subtracts it.
     *
     * @return array{0: ParameterDraft, 1: string, 2: list<string>}|null
     */
    private function container(string $name): ?array
    {
        $segments = FieldPath::segments(FieldPath::fromQueryName($name));
        if (count($segments) < 2) {
            return null;
        }

        $container = array_shift($segments);
        if (! $this->operation->hasParameter('query', $container)) {
            return null;
        }

        $parameter = $this->operation->parameter('query', $container);
        if (! self::isContainer($parameter)) {
            return null;
        }

        return [$parameter, $container, $segments];
    }

    /** Whether a parameter publishes its members as the object this class is about. */
    private static function isContainer(ParameterDraft $parameter): bool
    {
        return $parameter->resolvedField('style') === 'deepObject';
    }

    /**
     * One container's members and their own members, each as a bracketed query name.
     *
     * @param  non-empty-list<string>  $prefix
     * @return list<string>
     */
    private static function descend(SchemaDraft $schema, array $prefix): array
    {
        $names = [];

        foreach ($schema->propertyNames() as $member) {
            $path = [...$prefix, $member];
            $names[] = FieldPath::toQueryName($path);

            if ($schema->hasProperty($member)) {
                $names = [...$names, ...self::descend($schema->property($member), $path)];
            }
        }

        return $names;
    }
}
