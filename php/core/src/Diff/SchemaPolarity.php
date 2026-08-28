<?php

declare(strict_types=1);

namespace Docuccino\Core\Diff;

use Docuccino\Core\Draft\SchemaKeywords;
use Docuccino\Core\Extensions\Schema\ComponentNames;

/**
 * One recorded decision per subschema-carrying keyword: which way a change UNDER it moves the schema
 * carrying it, what a member arriving or leaving that position means, and how two sides' members are
 * paired. {@see SchemaComparator} is the only reader; the reason it cannot answer any of this from a
 * keyword's position alone is that `items` and `not` sit at the same position and point opposite ways.
 *
 * POLARITY governs a change UNDER the position and nothing else. Whether the position is there at all
 * is MEMBER's question below, and that one is always exact — no `not` rejects nothing and `not: {}`
 * rejects everything, whatever the polarity of an edit inside it turns out to be:
 *  - `DIRECT` — narrowing the subschema narrows the parent, so the child's classification carries
 *    up unchanged. Every position that constrains the value's own members reads this way.
 *  - `INVERSE` — narrowing the subschema WIDENS the parent (`not`, and only `not`).
 *  - `CONDITIONAL` — no polarity can be computed. `if` moves instances between the `then` and `else`
 *    branches, so narrowing it widens where there is no `else` and is indeterminate where there is;
 *    `$defs`/`definitions` are a STORE rather than an assertion, so a member's polarity is whatever
 *    the `$ref`s naming it are worth, which this class does not resolve.
 *
 * The direction an INVERSE or CONDITIONAL child moves the parent in is exactly what cannot be
 * computed, so nothing tries: the child's own code and path are published unchanged — each a true
 * statement about the subschema the path names — and the VERDICT is forced to breaking. For a release
 * gate a false alarm costs the author one look and a false "safe" costs the consumer a broken client,
 * so the indeterminate case is breaking by decision, not by accident. An annotation-only edit is the
 * one exception, because it moves no contract at any position ({@see SchemaKeywords::annotationOnly()}).
 *
 * `then` and `else` are DIRECT rather than conditional, which is a correction to the obvious reading:
 * `{if: A, then: B}` accepts `(A ∧ B) ∨ ¬A` and `{if: A, else: C}` accepts `A ∨ (¬A ∧ C)`, and
 * narrowing B or C narrows either set. Only `if` itself is non-monotone.
 *
 * MEMBER is what presence means at the position, which is a separate question from polarity because at
 * most positions an absent subschema and the empty schema mean the same thing and at four they do not:
 *  - `EMPTY` — absent IS the empty schema (no `items` constrains no element), so a member arriving or
 *    leaving falls out of the ordinary keyword comparison and needs no code of its own.
 *  - `CONSTRAINT` — absent is not the empty schema, and arriving narrows: `not: {}` rejects every
 *    value while no `not` rejects none.
 *  - `BOUNDED` — `contains`, whose arrival narrows only while `minContains` is at least 1: at
 *    `minContains: 0` the keyword asserts nothing and the bounds are the whole claim.
 *  - `UNION` — a branch of `anyOf`/`oneOf`. Removing one narrows the union and is breaking either way;
 *    adding one widens what a request accepts and is safe there, while a response can now carry a
 *    shape no existing reader has a case for — the `schema.enum-value-added` argument exactly, so it
 *    is breaking on a response.
 *  - `STORE` — a `$defs` member. Arriving is nothing (nothing can name a definition that did not also
 *    change); leaving may dangle a `$ref` this class does not resolve, so it is breaking.
 *  - `PROPERTY` and `REQUIRED` — the two positions with a comparison of their own
 *    ({@see SchemaComparator::compareProperties()} and `dependentRequired`'s per-property lists).
 *
 * PAIRING is how two sides' members are matched. `KEY` and `INDEX` are the position's own semantics —
 * a property name is its identity, and a `prefixItems` index IS the tuple slot it constrains.
 * `CONTENT` is the composition lists, where nothing but the member itself names it: they pair by what a
 * member IS, never by where it sits, on the ladder {@see SchemaComparator::pairBranches()} spells out —
 * {@see ComponentNames}' rule (identity first, content second, position never) applied to branches.
 *
 * Every keyword {@see SchemaKeywords} gives a subschema position needs a row here, and a keyword with
 * no row is read CONDITIONALLY ({@see rule()}) rather than skipped — a keyword the draft model learns
 * before anyone decides its polarity is reported conservatively instead of passing as safe. That is a
 * degradation and not a plan: `SchemaCompositionDiffTest` fails until the row is written.
 *
 * @phpstan-type Rule array{polarity: string, member: string, pairing: string, code: string|null}
 *
 * @internal
 */
final class SchemaPolarity
{
    /** Narrowing the subschema narrows the schema carrying it. */
    public const string DIRECT = 'direct';

    /** Narrowing the subschema widens the schema carrying it. */
    public const string INVERSE = 'inverse';

    /** Neither — the change is reported and classed breaking. */
    public const string CONDITIONAL = 'conditional';

    /** An absent subschema and the empty schema mean the same thing here. */
    public const string MEMBER_EMPTY = 'empty';

    /** Absent is not the empty schema: the keyword arriving narrows. */
    public const string MEMBER_CONSTRAINT = 'constraint';

    /** `contains`, whose arrival narrows only while `minContains` is at least 1. */
    public const string MEMBER_BOUNDED = 'bounded';

    /** A branch of a union: removing narrows, adding widens (and is breaking on a response). */
    public const string MEMBER_UNION = 'union';

    /** A `$defs` member — a store a `$ref` may name, not an assertion. */
    public const string MEMBER_STORE = 'store';

    /** `properties`, which has a comparison of its own. */
    public const string MEMBER_PROPERTY = 'property';

    /** `dependentRequired`, whose members are string lists rather than subschemas. */
    public const string MEMBER_REQUIRED = 'required';

    /** Members are matched by their map key. */
    public const string PAIRING_KEY = 'key';

    /** Members are matched by list index, because the index is the contract. */
    public const string PAIRING_INDEX = 'index';

    /** Members are matched by identity, then by content — never by position. */
    public const string PAIRING_CONTENT = 'content';

    /** The position holds a single subschema, so there is nothing to pair. */
    public const string PAIRING_NONE = 'none';

    /**
     * The decision for every keyword carrying a subschema, keyword => rule. `code` is the stem of the
     * `schema.<stem>-added` / `schema.<stem>-removed` pair a position with its own presence semantics
     * publishes, and null where absence needs no code because it is the empty schema.
     *
     * This IS a second table keyed by the positioned keywords, and what keeps it from going stale is the
     * derived guard in `SchemaCompositionDiffTest` — which reads {@see decided()} against the draft
     * model's own set, in both directions — rather than the source scan in `DeclaredShapeTest`: a
     * keyword => RECORD table is a decision per keyword instead of a copy of the set, which is why that
     * scan walks past this one exactly as it walks past `SchemaKeywords`' refinement table.
     *
     * @var array<string, Rule>
     */
    private const array RULES = [
        // Draft-07's tail of a tuple, and the two positions every object contract is typed against.
        'additionalItems' => ['polarity' => self::DIRECT, 'member' => self::MEMBER_EMPTY, 'pairing' => self::PAIRING_NONE, 'code' => null],
        'additionalProperties' => ['polarity' => self::DIRECT, 'member' => self::MEMBER_EMPTY, 'pairing' => self::PAIRING_NONE, 'code' => null],
        'items' => ['polarity' => self::DIRECT, 'member' => self::MEMBER_EMPTY, 'pairing' => self::PAIRING_NONE, 'code' => null],
        'properties' => ['polarity' => self::DIRECT, 'member' => self::MEMBER_PROPERTY, 'pairing' => self::PAIRING_KEY, 'code' => null],
        // An intersection: every branch holds, so one added narrows and one removed widens.
        'allOf' => ['polarity' => self::DIRECT, 'member' => self::MEMBER_CONSTRAINT, 'pairing' => self::PAIRING_CONTENT, 'code' => 'all-of-branch'],
        // Unions. `oneOf` demands exactly one match, which is non-monotone where two branches overlap
        // — but a value matching two `oneOf` branches validates against neither, so an overlapping
        // `oneOf` is already a contract no generated client can read. The monotone reading is the one
        // that is true of every well-formed `oneOf`, and it is the reading both take.
        'anyOf' => ['polarity' => self::DIRECT, 'member' => self::MEMBER_UNION, 'pairing' => self::PAIRING_CONTENT, 'code' => 'any-of-branch'],
        'oneOf' => ['polarity' => self::DIRECT, 'member' => self::MEMBER_UNION, 'pairing' => self::PAIRING_CONTENT, 'code' => 'one-of-branch'],
        // A tuple: index 2 is index 2, so the index pairs and a reorder is a real change at each slot.
        'prefixItems' => ['polarity' => self::DIRECT, 'member' => self::MEMBER_EMPTY, 'pairing' => self::PAIRING_INDEX, 'code' => null],
        'contains' => ['polarity' => self::DIRECT, 'member' => self::MEMBER_BOUNDED, 'pairing' => self::PAIRING_NONE, 'code' => 'contains'],
        'not' => ['polarity' => self::INVERSE, 'member' => self::MEMBER_CONSTRAINT, 'pairing' => self::PAIRING_NONE, 'code' => 'not'],
        'if' => ['polarity' => self::CONDITIONAL, 'member' => self::MEMBER_EMPTY, 'pairing' => self::PAIRING_NONE, 'code' => null],
        'then' => ['polarity' => self::DIRECT, 'member' => self::MEMBER_EMPTY, 'pairing' => self::PAIRING_NONE, 'code' => null],
        'else' => ['polarity' => self::DIRECT, 'member' => self::MEMBER_EMPTY, 'pairing' => self::PAIRING_NONE, 'code' => null],
        // A member arriving at either constrains what used to be unconstrained, and an absent member
        // constrains nothing — which is what the empty schema there says too.
        'patternProperties' => ['polarity' => self::DIRECT, 'member' => self::MEMBER_EMPTY, 'pairing' => self::PAIRING_KEY, 'code' => null],
        'dependentSchemas' => ['polarity' => self::DIRECT, 'member' => self::MEMBER_EMPTY, 'pairing' => self::PAIRING_KEY, 'code' => null],
        'propertyNames' => ['polarity' => self::DIRECT, 'member' => self::MEMBER_EMPTY, 'pairing' => self::PAIRING_NONE, 'code' => null],
        'unevaluatedItems' => ['polarity' => self::DIRECT, 'member' => self::MEMBER_EMPTY, 'pairing' => self::PAIRING_NONE, 'code' => null],
        'unevaluatedProperties' => ['polarity' => self::DIRECT, 'member' => self::MEMBER_EMPTY, 'pairing' => self::PAIRING_NONE, 'code' => null],
        // The decoded content of a string, so narrowing it narrows what the string may hold.
        'contentSchema' => ['polarity' => self::DIRECT, 'member' => self::MEMBER_EMPTY, 'pairing' => self::PAIRING_NONE, 'code' => null],
        '$defs' => ['polarity' => self::CONDITIONAL, 'member' => self::MEMBER_STORE, 'pairing' => self::PAIRING_KEY, 'code' => 'definition'],
        'definitions' => ['polarity' => self::CONDITIONAL, 'member' => self::MEMBER_STORE, 'pairing' => self::PAIRING_KEY, 'code' => 'definition'],
        'dependentRequired' => ['polarity' => self::DIRECT, 'member' => self::MEMBER_REQUIRED, 'pairing' => self::PAIRING_KEY, 'code' => 'dependent-required'],
    ];

    /**
     * The rule for `$keyword`. A keyword nobody has decided is read CONDITIONALLY — reported, and
     * classed breaking — rather than skipped: silence is the one answer a release gate cannot recover
     * from. Its members pair by content, which is the only rung available without knowing the position.
     *
     * @return Rule
     */
    public static function rule(string $keyword): array
    {
        return self::RULES[$keyword] ?? [
            'polarity' => self::CONDITIONAL,
            'member' => self::MEMBER_EMPTY,
            'pairing' => SchemaKeywords::positionOf($keyword) === SchemaKeywords::POSITION_SCHEMA_LIST
                ? self::PAIRING_CONTENT
                : self::PAIRING_KEY,
            'code' => null,
        ];
    }

    /**
     * Whether a member ARRIVING or LEAVING at a position gates, which is the presence half of the
     * decision and exact wherever it is recorded at all: a constraint arriving narrows; a union branch
     * arriving widens what a request takes and hands a response reader a shape it has no case for; a
     * `$defs` member arriving is nothing while one leaving may dangle a `$ref` nothing here resolves;
     * and `contains` arriving narrows only while it asserts something, which `$asserts` carries because
     * reading `minContains` is the comparator's job rather than this class's.
     *
     * `MEMBER_EMPTY` has no presence decision to apply — absence and the empty schema are supposed to
     * say the same thing there, so presence is meant to fall out of the ordinary keyword comparison and
     * never reach here. Arriving at this answer means a position nobody decided has members arriving and
     * leaving, and the direction is then exactly what is unknown: BREAKING, both ways. The one thing a
     * release gate cannot do is guess in the safe direction.
     */
    public static function presenceIsBreaking(string $member, bool $arriving, bool $request, bool $asserts): bool
    {
        return match ($member) {
            self::MEMBER_CONSTRAINT => $arriving,
            self::MEMBER_BOUNDED => $arriving && $asserts,
            self::MEMBER_UNION => ! $arriving || ! $request,
            self::MEMBER_STORE => ! $arriving,
            default => true,
        };
    }

    /**
     * Every member kind there is, so the dataset over {@see presenceIsBreaking()} reads the set rather
     * than a second copy of it — a kind added with no verdict of its own falls to the conservative arm,
     * and the guard is what says so out loud.
     *
     * @return list<string>
     */
    public static function memberKinds(): array
    {
        $kinds = [];

        foreach ((new \ReflectionClass(self::class))->getConstants() as $name => $value) {
            if (str_starts_with($name, 'MEMBER_') && is_string($value)) {
                $kinds[] = $value;
            }
        }

        return $kinds;
    }

    /**
     * Every keyword a decision has been recorded for, so the guard reads this set rather than a second
     * copy of it. {@see SchemaKeywords::positionOf()} is the set it is checked against, in both
     * directions — a keyword the draft model learns and a keyword only this table knows are both a
     * decision nobody made.
     *
     * @return list<string>
     */
    public static function decided(): array
    {
        return array_keys(self::RULES);
    }
}
