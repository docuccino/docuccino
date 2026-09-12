<?php

declare(strict_types=1);

namespace Docuccino\Core\Extensions\Validation;

use Docuccino\Attributes\BodyParameter;
use Docuccino\Attributes\QueryParameter;
use Docuccino\Core\Inference\DType\UnknownT;
use Docuccino\Core\TypeGrammar\TypeStringParser;

/**
 * What the author's parameter declarations already say about a route's validated fields, and the two
 * questions a rules recoverer asks of them before it reports what became of a field whose rules it
 * could not read: is the field in the document anyway, and did a declaration decide which container it
 * is. A note that speaks without asking asserts what a later layer has already decided, and names a
 * remedy the author went around.
 *
 * One class for both layers because the question is one, and one flag inside it because the WRITERS
 * differ — a guard reads the same grammar as the write it guards:
 *
 * - a `#[BodyParameter]` is written INTO the recovered body ({@see DeclaredBodyFields}), the declared
 *   node going in whole, so a declaration anywhere on a field's BRANCH decides that field. Above it,
 *   the field is replaced and no rule the author writes can put it back; at or inside it, the field is
 *   published as what the declaration says, its container settled by the writing.
 * - a `#[QueryParameter]` mints ONE parameter per name ({@see RecoveredRequest::apply()}) and leaves
 *   every other parameter as the recovery left it, so it answers for the field it NAMES and no other,
 *   and it decides a container only by stating a type — with none it writes no schema at all.
 *
 * @phpstan-type DeclaredField array{path: string, type: string|null}
 */
final class DeclaredFields
{
    private readonly TypeStringParser $types;

    /**
     * @param  list<DeclaredField>  $declarations
     * @param  bool  $wholeBranch  whether a declaration decides a field's whole branch — see the header
     */
    private function __construct(
        private readonly array $declarations,
        private readonly bool $wholeBranch,
    ) {
        $this->types = new TypeStringParser;
    }

    /**
     * The declarations that document a request BODY.
     *
     * @param  list<BodyParameter>  $declarations
     */
    public static function inBody(array $declarations): self
    {
        return new self(
            array_map(static fn (BodyParameter $each): array => ['path' => $each->name, 'type' => $each->type], $declarations),
            wholeBranch: true,
        );
    }

    /**
     * The declarations that document QUERY parameters, read back into the path grammar the rules are
     * keyed by ({@see FieldPath::queryNameAsPath()}). One whose bracketed name has no spelling there
     * answers for no field, which is what it does on the wire too.
     *
     * @param  list<QueryParameter>  $declarations
     */
    public static function inQuery(array $declarations): self
    {
        /** @var list<DeclaredField> $paths */
        $paths = [];

        foreach ($declarations as $each) {
            $path = FieldPath::queryNameAsPath($each->name);
            if ($path !== null) {
                $paths[] = ['path' => $path, 'type' => $each->type];
            }
        }

        return new self($paths, wholeBranch: false);
    }

    /**
     * Whether a declaration answers for `$field` — asked before a recoverer says the field is omitted,
     * or that a constraint of it was left off.
     */
    public function publishes(string $field): bool
    {
        foreach ($this->declarations as $declaration) {
            if ($this->reaches($declaration['path'], $field)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Whether a declaration settles which container `$field` is — narrower than {@see publishes()},
     * because a declaration can publish a field without saying which of the two shapes it takes.
     *
     * A declaration that is not AT the field settles it by existing, which only the body layer reaches:
     * a path inside proves a container, one above replaces the field outright. A declaration AT the
     * field settles it only as far as its type does, read by the parser that will do the writing —
     * `array` and `mixed` resolve to no shape and publish the empty schema, which decides neither
     * container. With no type at all the two layers part company: the body writes the attribute's own
     * default of `string`, while a query parameter writes nothing and leaves the recovered "either"
     * standing.
     */
    public function decidesContainer(string $field): bool
    {
        foreach ($this->declarations as $declaration) {
            if (! $this->reaches($declaration['path'], $field)) {
                continue;
            }

            if (FieldPath::segments($declaration['path']) !== FieldPath::segments($field)) {
                return true;
            }

            if ($declaration['type'] === null) {
                if ($this->wholeBranch) {
                    return true;
                }

                continue;
            }

            if (! $this->types->parseDeclared($declaration['type']) instanceof UnknownT) {
                return true;
            }
        }

        return false;
    }

    /**
     * Whether one declared path answers for `$field`. A path with an empty segment names no field and
     * documents nothing, so there is nothing for it to have answered.
     *
     * A well-formed body path the body then turns out not to be able to carry — a scalar, a composition
     * or a `$ref` parent — still counts: this is asked during recovery, with no body yet to ask, and the
     * refusal is reported where it happens, against the declaration itself, where a second note asking
     * for rules would name the wrong remedy for the same mistake.
     */
    private function reaches(string $path, string $field): bool
    {
        if (! FieldPath::isWellFormed($path)) {
            return false;
        }

        if (! $this->wholeBranch) {
            return FieldPath::segments($path) === FieldPath::segments($field);
        }

        return FieldPath::isAtOrUnder($path, $field) || FieldPath::isAtOrUnder($field, $path);
    }
}
