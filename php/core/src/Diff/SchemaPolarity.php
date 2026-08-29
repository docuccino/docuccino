<?php

declare(strict_types=1);

namespace Docuccino\Core\Diff;

use Docuccino\Core\Draft\SchemaKeywords;

/**
 * One recorded decision per subschema-carrying keyword: which way a change UNDER it moves the schema
 * carrying it, what the position or one of its members arriving or leaving means, and whether its
 * members pair by index. {@see SchemaComparator} is the only reader; the reason it cannot answer any of
 * this from a keyword's position alone is that `items` and `not` sit at the same position and point
 * opposite ways. The three axes, and why `then`/`else` are DIRECT where `if` is not, are in
 * docs/design/uir-and-extensions.md §1 "Diff polarity".
 *
 * @phpstan-type Rule array{polarity: string, member: SchemaMember, pairsByIndex: bool, code: string|null}
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

    /**
     * The decision for every keyword carrying a subschema, keyword => rule. `code` is the stem of the
     * `schema.<stem>-added` / `schema.<stem>-removed` pair a position with its own presence semantics
     * publishes, and null where absence needs no code because it is the empty schema. `pairsByIndex` is
     * the one pairing fact the comparator branches on: a `prefixItems` slot IS the tuple position it
     * constrains, while everywhere else members pair by what they are ({@see SchemaComparator::pairBranches()})
     * or by their map key, which the position already says.
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
        'additionalItems' => ['polarity' => self::DIRECT, 'member' => SchemaMember::EmptySchema, 'pairsByIndex' => false, 'code' => null],
        'additionalProperties' => ['polarity' => self::DIRECT, 'member' => SchemaMember::EmptySchema, 'pairsByIndex' => false, 'code' => null],
        'items' => ['polarity' => self::DIRECT, 'member' => SchemaMember::EmptySchema, 'pairsByIndex' => false, 'code' => null],
        'properties' => ['polarity' => self::DIRECT, 'member' => SchemaMember::Property, 'pairsByIndex' => false, 'code' => null],
        // An intersection: every branch holds, so one added narrows and one removed widens.
        'allOf' => ['polarity' => self::DIRECT, 'member' => SchemaMember::Constraint, 'pairsByIndex' => false, 'code' => 'all-of-branch'],
        // Unions. `oneOf` demands exactly one match, which is non-monotone where two branches overlap
        // — but a value matching two `oneOf` branches validates against neither, so an overlapping
        // `oneOf` is already a contract no generated client can read. The monotone reading is the one
        // that is true of every well-formed `oneOf`, and it is the reading both take.
        'anyOf' => ['polarity' => self::DIRECT, 'member' => SchemaMember::Union, 'pairsByIndex' => false, 'code' => 'any-of-branch'],
        'oneOf' => ['polarity' => self::DIRECT, 'member' => SchemaMember::Union, 'pairsByIndex' => false, 'code' => 'one-of-branch'],
        // A tuple: index 2 is index 2, so the index pairs and a reorder is a real change at each slot.
        'prefixItems' => ['polarity' => self::DIRECT, 'member' => SchemaMember::EmptySchema, 'pairsByIndex' => true, 'code' => null],
        'contains' => ['polarity' => self::DIRECT, 'member' => SchemaMember::Bounded, 'pairsByIndex' => false, 'code' => 'contains'],
        'not' => ['polarity' => self::INVERSE, 'member' => SchemaMember::Constraint, 'pairsByIndex' => false, 'code' => 'not'],
        'if' => ['polarity' => self::CONDITIONAL, 'member' => SchemaMember::EmptySchema, 'pairsByIndex' => false, 'code' => null],
        'then' => ['polarity' => self::DIRECT, 'member' => SchemaMember::EmptySchema, 'pairsByIndex' => false, 'code' => null],
        'else' => ['polarity' => self::DIRECT, 'member' => SchemaMember::EmptySchema, 'pairsByIndex' => false, 'code' => null],
        // A member arriving at either constrains what used to be unconstrained, and an absent member
        // constrains nothing — which is what the empty schema there says too.
        'patternProperties' => ['polarity' => self::DIRECT, 'member' => SchemaMember::EmptySchema, 'pairsByIndex' => false, 'code' => null],
        'dependentSchemas' => ['polarity' => self::DIRECT, 'member' => SchemaMember::EmptySchema, 'pairsByIndex' => false, 'code' => null],
        'propertyNames' => ['polarity' => self::DIRECT, 'member' => SchemaMember::EmptySchema, 'pairsByIndex' => false, 'code' => null],
        'unevaluatedItems' => ['polarity' => self::DIRECT, 'member' => SchemaMember::EmptySchema, 'pairsByIndex' => false, 'code' => null],
        'unevaluatedProperties' => ['polarity' => self::DIRECT, 'member' => SchemaMember::EmptySchema, 'pairsByIndex' => false, 'code' => null],
        // The decoded content of a string, so narrowing it narrows what the string may hold.
        'contentSchema' => ['polarity' => self::DIRECT, 'member' => SchemaMember::EmptySchema, 'pairsByIndex' => false, 'code' => null],
        '$defs' => ['polarity' => self::CONDITIONAL, 'member' => SchemaMember::Store, 'pairsByIndex' => false, 'code' => 'definition'],
        'definitions' => ['polarity' => self::CONDITIONAL, 'member' => SchemaMember::Store, 'pairsByIndex' => false, 'code' => 'definition'],
        'dependentRequired' => ['polarity' => self::DIRECT, 'member' => SchemaMember::Required, 'pairsByIndex' => false, 'code' => 'dependent-required'],
    ];

    /**
     * The rule for `$keyword`. A keyword nobody has decided is read CONDITIONALLY — reported, and
     * classed breaking — rather than skipped: silence is the one answer a release gate cannot recover
     * from. A keyword arrives from a document, so the fallback is a runtime one; a {@see SchemaMember}
     * never does, which is why that axis is decided at analysis time instead.
     *
     * @return Rule
     */
    public static function rule(string $keyword): array
    {
        return self::RULES[$keyword] ?? [
            'polarity' => self::CONDITIONAL,
            'member' => SchemaMember::EmptySchema,
            'pairsByIndex' => false,
            'code' => null,
        ];
    }

    /**
     * Whether the KEYWORD arriving or leaving gates — the whole position, not one of its members, so the
     * baseline on the side without it is no constraint of that kind at all. A constraint arriving
     * narrows; `contains` arriving narrows only while it asserts something, which `$asserts` carries
     * because reading its bounds is the comparator's job rather than this class's; and a union arriving
     * narrows on BOTH sides, because the side that had no `anyOf` was not an empty union — it was
     * unconstrained. A union LEAVING is the mirror of `schema.enum-removed`: a request widens, while a
     * response reader loses the closed set of shapes it typed against.
     *
     * The kinds that cannot reach here are conservative rather than absent: at a position nobody decided
     * the direction is exactly what is unknown, and the one thing a release gate cannot do is guess in
     * the safe direction.
     */
    public static function keywordPresenceIsBreaking(SchemaMember $member, bool $arriving, bool $request, bool $asserts): bool
    {
        return match ($member) {
            SchemaMember::Constraint => $arriving,
            SchemaMember::Bounded => $arriving && $asserts,
            SchemaMember::Union => $arriving || ! $request,
            // `$defs`, `properties` and `dependentRequired` report per member and never the keyword;
            // an EMPTY position has no presence claim to make at all.
            SchemaMember::Store, SchemaMember::Property, SchemaMember::Required, SchemaMember::EmptySchema => true,
        };
    }

    /**
     * Whether ONE member of a position arriving or leaving gates, which is a different question from the
     * keyword above: here the position stood on both sides and the union, intersection or store already
     * existed. A branch of an intersection arriving narrows; a branch of a union arriving widens what a
     * request takes and hands a response reader a shape it has no case for; a `$defs` member arriving is
     * nothing while one leaving may dangle a `$ref` nothing here resolves; and a `dependentRequired`
     * entry narrows what a request accepts exactly as `required` does, which is why the response side is
     * a report rather than a verdict.
     */
    public static function memberPresenceIsBreaking(SchemaMember $member, bool $arriving, bool $request): bool
    {
        return match ($member) {
            SchemaMember::Constraint => $arriving,
            SchemaMember::Union => ! $arriving || ! $request,
            SchemaMember::Store => ! $arriving,
            SchemaMember::Required => $arriving && $request,
            // `contains` holds one subschema and `properties` has a comparison of its own, so neither
            // has members reaching here; an EMPTY position's members fall out of the keyword comparison.
            SchemaMember::Bounded, SchemaMember::Property, SchemaMember::EmptySchema => true,
        };
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
