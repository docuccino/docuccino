<?php

declare(strict_types=1);

namespace Docuccino\Core\Diff;

use Docuccino\Core\Draft\SchemaKeywords;
use Docuccino\Core\Extensions\Schema\ComponentNames;
use Docuccino\Core\Support\Arr;
use Docuccino\Core\Support\Hydrate;

/**
 * Field-level schema comparison with breaking-change classification. `$ref`s compare opaquely, by
 * target string — the referenced component's own changes are reported once, where that component
 * is diffed by identity — so nothing double-counts and no ref resolution or cycle handling is
 * needed.
 *
 * Breaking: a type narrowed/changed or a constraint added; an enum value removed or an enum
 * introduced; an enum value added to, or the enum constraint dropped from, a *response* schema —
 * a reader (a generated client above all) meets a value it has no case for, or loses a closed set
 * it typed against; a required property added to a *request* schema; a property removed from a
 * *response* schema; a `format` added or changed on a *request* schema (stricter input).
 * Non-breaking: type widened, an enum value added to a request schema (old writers stay valid),
 * required removed, description edits, property added, a property removed from a request schema
 * (the client just stops sending it), a format removed or any format change on a response.
 * `required` on a response/component schema — usage context unknown — is reported but classed
 * non-breaking; that's a judgment call, not an oversight. An enum value removed, and an enum
 * introduced, stay breaking on BOTH sides: each is safe for a pure reader, but a schema's
 * `request` flag can under-state its audience (a shared component serves both directions), and a
 * downgrade there green-lights a change that rejects a writer's previously valid value.
 *
 * An ANNOTATION keyword is the one class of edit that is never any of the above: it says what a value
 * means and nothing about what it may be, so a change to one is reported — a reviewer asking what moved
 * is owed the answer — and is never breaking under any versioning policy. Which keywords those are is
 * {@see SchemaKeywords::isAnnotationOnly()}'s answer and not a list kept here. Nothing here asks whether
 * an example is VALID: `ExampleSchemaLint` and `RecordedExampleAudit` hold every published example to
 * the schema beside it, on every build, with the same validator — the differ classifies the keyword,
 * the audit owns validity.
 *
 * A subschema may be a BOOLEAN, which is where the classification above stops being enough: `true` is
 * the empty schema and reads as one, but `false` is spelled by no set of keywords, so it is compared as
 * itself ({@see compareSubschema()}) and arriving is breaking on both sides — the tightest narrowing the
 * language has (docs/design/uir-and-extensions.md §1 "The empty-object invariant").
 *
 * EVERY subschema position is descended into, composition and conditional keywords included, and what
 * a change under one is worth is {@see SchemaPolarity}'s recorded decision per keyword rather than one
 * classification stretched over all of them — because the polarity DIFFERS by keyword, and a shared
 * classification would report the direction backwards. `properties` keeps a comparison of its own (a
 * member there is a property a consumer reads, not a constraint), the `contains` bounds are compared
 * beside the keyword they bound, and everything else runs through {@see comparePositions()}.
 *
 * Every REFINEMENT keyword is read too, and the direction is again a recorded decision per keyword
 * ({@see SchemaRefinement}) rather than one rule stretched over all of them, because `maximum` and
 * `minimum` sit at the same position and point opposite ways. A bound tightened is breaking on both
 * sides, one relaxed is the `schema.enum-value-added` argument on a response, and a change nothing can
 * order — two `pattern`s, two dialects of `exclusiveMinimum` — is reported and classed breaking rather
 * than waved through. `contains`' own `minContains`/`maxContains` keep the comparison beside the
 * keyword they bound ({@see compareContainsBounds()}), because they are inert without it and because
 * whether they assert anything is what decides what that keyword's presence is worth.
 *
 * Two things neither decision tries to answer, recorded here because a silent limit reads as a claim.
 * A keyword's polarity is its own; where 2020-12 makes one keyword's outcome depend on a SIBLING —
 * `contains` and `then` marking items and properties evaluated, so an `unevaluatedItems: false` beside
 * them means something different, and draft-04's boolean `exclusiveMinimum` modifying the `minimum`
 * beside it — that interaction is unread, and both halves are reported on their own terms. And a
 * `$ref` still compares opaquely, so a branch naming a component carries whatever that component's own
 * diff says.
 *
 * @phpstan-import-type Rule from SchemaPolarity
 */
final class SchemaComparator
{
    /**
     * Two Schema Objects at one position. `mixed`, because a Schema Object may be a BOOLEAN at every
     * position OpenAPI puts one — a media type's `schema` as much as an `items` inside it.
     *
     * @return list<Change>
     */
    public function compare(mixed $old, mixed $new, string $path, string $id, bool $request): array
    {
        return $this->compareSubschema($old, $new, $path, $id, $request);
    }

    /**
     * @param  array<string, mixed>  $old
     * @param  array<string, mixed>  $new
     * @return list<Change>
     */
    private function compareKeywords(array $old, array $new, string $path, string $id, bool $request): array
    {
        $changes = [];

        $this->compareRef($old, $new, $path, $id, $changes);
        $this->compareAnnotations($old, $new, $path, $id, $changes);
        $this->compareType($old, $new, $path, $id, $changes);
        $this->compareFormat($old, $new, $path, $id, $request, $changes);
        $this->compareEnum($old, $new, $path, $id, $request, $changes);
        $this->compareRequired($old, $new, $path, $id, $request, $changes);
        $this->compareRefinements($old, $new, $path, $id, $request, $changes);
        $this->compareContainsBounds($old, $new, $path, $id, $changes);
        $this->comparePositions($old, $new, $path, $id, $request, $changes);

        return $changes;
    }

    /**
     * Two subschemas at one position, either of which may be a boolean. A boolean IS a schema, and at
     * these positions it is the most load-bearing value there is: `true` is the empty schema and
     * normalises to one, so every comparison above reads it correctly, while `false` is satisfied by
     * nothing and is the one value compared as itself.
     *
     * An ABSENT subschema reads as the empty one, because that is what it means at nearly every position
     * — no `items` constrains no element, and `additionalProperties` defaults to `true`. So is a value
     * that is no schema at all, which is the widening the canonicaliser publishes for it. The positions
     * where absence says the OPPOSITE of the empty schema (`not: {}` rejects every value; `contains: {}`
     * demands an element) never reach here with one side absent: {@see compareSinglePosition()} settles
     * presence there first, on {@see SchemaPolarity}'s recorded decision.
     *
     * @return list<Change>
     */
    private function compareSubschema(mixed $old, mixed $new, string $path, string $id, bool $request): array
    {
        $wasNothing = $old === false;
        $isNothing = $new === false;

        if ($wasNothing === $isNothing) {
            // Two schemas that admit nothing are the same schema, whatever else either says.
            return $isNothing ? [] : $this->compareKeywords(self::asSchema($old), self::asSchema($new), $path, $id, $request);
        }

        // Breaking on BOTH sides when it arrives: a request now rejects every value a writer used to
        // send, and a response can no longer carry the value a reader was reading. Going is the exact
        // inverse — nothing was valid, now something is — and widens like any dropped constraint.
        return [$isNothing
            ? $this->change(ChangeKind::Changed, $id, $path, true, 'schema.always-invalid-added', null, null, null)
            : $this->change(ChangeKind::Changed, $id, $path, false, 'schema.always-invalid-removed', null, null, null)];
    }

    /**
     * @param  array<string, mixed>  $old
     * @param  array<string, mixed>  $new
     * @param  list<Change>  $changes
     */
    private function compareRef(array $old, array $new, string $path, string $id, array &$changes): void
    {
        $oldRef = Hydrate::stringOrNull($old['$ref'] ?? null);
        $newRef = Hydrate::stringOrNull($new['$ref'] ?? null);

        if ($oldRef !== null && $newRef !== null && $oldRef !== $newRef) {
            $changes[] = $this->change(ChangeKind::Changed, $id, $path.'.$ref', false, 'schema.ref-changed', '$ref', $oldRef, $newRef);
        }
    }

    /**
     * Every annotation-only keyword either side carries, compared by the fingerprint {@see ValueKey}
     * rather than by `===`: two `stdClass` standing for one JSON object are never the identical one.
     * Non-breaking by construction, and the only place in this class where that is not a judgment about
     * the two values.
     *
     * @param  array<string, mixed>  $old
     * @param  array<string, mixed>  $new
     * @param  list<Change>  $changes
     */
    private function compareAnnotations(array $old, array $new, string $path, string $id, array &$changes): void
    {
        foreach (Arr::sortedUnion(array_keys($old), array_keys($new)) as $keyword) {
            if (! SchemaKeywords::isAnnotationOnly($keyword)) {
                continue;
            }

            $before = $old[$keyword] ?? null;
            $after = $new[$keyword] ?? null;

            if (ValueKey::of($before) === ValueKey::of($after)) {
                continue;
            }

            $changes[] = $this->change(ChangeKind::Changed, $id, $path.'.'.$keyword, false, 'schema.annotation-changed', $keyword, $before, $after);
        }
    }

    /**
     * @param  array<string, mixed>  $old
     * @param  array<string, mixed>  $new
     * @param  list<Change>  $changes
     */
    private function compareType(array $old, array $new, string $path, string $id, array &$changes): void
    {
        $oldSet = self::typeSet($old);
        $newSet = self::typeSet($new);

        if ($oldSet === $newSet) {
            return;
        }

        $oldValue = $old['type'] ?? null;
        $newValue = $new['type'] ?? null;

        if ($oldSet === []) {
            $changes[] = $this->change(ChangeKind::Changed, $id, $path.'.type', true, 'schema.type-added', 'type', $oldValue, $newValue);

            return;
        }

        if ($newSet === []) {
            $changes[] = $this->change(ChangeKind::Changed, $id, $path.'.type', false, 'schema.type-removed', 'type', $oldValue, $newValue);

            return;
        }

        if (self::isSuperset($newSet, $oldSet)) {
            $changes[] = $this->change(ChangeKind::Changed, $id, $path.'.type', false, 'schema.type-widened', 'type', $oldValue, $newValue);

            return;
        }

        $code = self::isSuperset($oldSet, $newSet) ? 'schema.type-narrowed' : 'schema.type-changed';
        $changes[] = $this->change(ChangeKind::Changed, $id, $path.'.type', true, $code, 'type', $oldValue, $newValue);
    }

    /**
     * @param  array<string, mixed>  $old
     * @param  array<string, mixed>  $new
     * @param  list<Change>  $changes
     */
    private function compareFormat(array $old, array $new, string $path, string $id, bool $request, array &$changes): void
    {
        $oldFormat = $old['format'] ?? null;
        $newFormat = $new['format'] ?? null;

        if ($oldFormat === $newFormat) {
            return;
        }

        // On a request, a format added or changed tightens what the API accepts. Removing one
        // widens; on a response format is only descriptive.
        $breaking = $request && $newFormat !== null;

        $changes[] = $this->change(ChangeKind::Changed, $id, $path.'.format', $breaking, 'schema.format-changed', 'format', $oldFormat, $newFormat);
    }

    /**
     * @param  array<string, mixed>  $old
     * @param  array<string, mixed>  $new
     * @param  list<Change>  $changes
     */
    private function compareEnum(array $old, array $new, string $path, string $id, bool $request, array &$changes): void
    {
        $hasOld = array_key_exists('enum', $old) && is_array($old['enum']);
        $hasNew = array_key_exists('enum', $new) && is_array($new['enum']);

        if (! $hasOld && ! $hasNew) {
            return;
        }

        $oldEnum = $hasOld ? array_values($old['enum']) : [];
        $newEnum = $hasNew ? array_values($new['enum']) : [];

        if (! $hasOld) {
            $changes[] = $this->change(ChangeKind::Changed, $id, $path.'.enum', true, 'schema.enum-added', 'enum', null, $newEnum);

            return;
        }

        if (! $hasNew) {
            // Dropping the constraint widens what a request accepts; on a response it turns a
            // closed set a reader typed against into anything at all.
            $changes[] = $this->change(ChangeKind::Changed, $id, $path.'.enum', ! $request, 'schema.enum-removed', 'enum', $oldEnum, null);

            return;
        }

        $removed = self::valueDiff($oldEnum, $newEnum);
        $added = self::valueDiff($newEnum, $oldEnum);

        if ($removed !== []) {
            $changes[] = $this->change(ChangeKind::Changed, $id, $path.'.enum', true, 'schema.enum-value-removed', 'enum', $oldEnum, $newEnum);

            return;
        }

        if ($added !== []) {
            // A new value widens what a request accepts; a response can now return something no
            // existing reader has a case for, and a strongly-typed generated client fails outright.
            $changes[] = $this->change(ChangeKind::Changed, $id, $path.'.enum', ! $request, 'schema.enum-value-added', 'enum', $oldEnum, $newEnum);
        }
    }

    /**
     * @param  array<string, mixed>  $old
     * @param  array<string, mixed>  $new
     * @param  list<Change>  $changes
     */
    private function compareRequired(array $old, array $new, string $path, string $id, bool $request, array &$changes): void
    {
        $oldRequired = Hydrate::stringList($old['required'] ?? null);
        $newRequired = Hydrate::stringList($new['required'] ?? null);

        $added = array_values(array_diff($newRequired, $oldRequired));
        $removed = array_values(array_diff($oldRequired, $newRequired));

        if ($added !== []) {
            $changes[] = $this->change(ChangeKind::Changed, $id, $path.'.required', $request, 'schema.required-added', 'required', $oldRequired, $newRequired);
        }

        if ($removed !== []) {
            $changes[] = $this->change(ChangeKind::Changed, $id, $path.'.required', false, 'schema.required-removed', 'required', $oldRequired, $newRequired);
        }
    }

    /**
     * @param  array<string, mixed>  $old
     * @param  array<string, mixed>  $new
     * @param  list<Change>  $changes
     */
    private function compareProperties(array $old, array $new, string $path, string $id, bool $request, array &$changes): void
    {
        $oldProps = self::properties($old);
        $newProps = self::properties($new);

        $names = Arr::sortedUnion(array_keys($oldProps), array_keys($newProps));

        foreach ($names as $name) {
            $propPath = $path.'.properties.'.$name;
            $oldProperty = $oldProps[$name] ?? null;
            $newProperty = $newProps[$name] ?? null;

            // A property declared `false` is FORBIDDEN, not declared — so it appearing or disappearing
            // is a constraint changing rather than a property changing, and never the `property-added`
            // a consumer can start reading. Reported as the narrowing it is, at either end.
            if ($oldProperty === false || $newProperty === false) {
                foreach ($this->compareSubschema($oldProperty, $newProperty, $propPath, $id, $request) as $child) {
                    $changes[] = $child;
                }

                continue;
            }

            // `array_key_exists`, not `isset`: a property declared null publishes as the empty schema,
            // and reading it as a missing member reported the property removed when it was still there.
            if (! array_key_exists($name, $newProps)) {
                // Losing a response property breaks whoever read it; on a request the client
                // just stops sending it.
                $changes[] = $this->change(ChangeKind::Removed, $id, $propPath, ! $request, 'schema.property-removed', null, null, null);

                continue;
            }

            if (! array_key_exists($name, $oldProps)) {
                $changes[] = $this->change(ChangeKind::Added, $id, $propPath, false, 'schema.property-added', null, null, null);

                continue;
            }

            foreach ($this->compareSubschema($oldProperty, $newProperty, $propPath, $id, $request) as $child) {
                $changes[] = $child;
            }
        }
    }

    /**
     * Every subschema position either side carries, in one sorted pass so the answer never depends on
     * which side declared what. What a position is worth is {@see SchemaPolarity}'s recorded decision;
     * a keyword it has no row for is read conservatively rather than skipped.
     *
     * @param  array<string, mixed>  $old
     * @param  array<string, mixed>  $new
     * @param  list<Change>  $changes
     */
    private function comparePositions(array $old, array $new, string $path, string $id, bool $request, array &$changes): void
    {
        foreach (Arr::sortedUnion(array_keys($old), array_keys($new)) as $keyword) {
            $position = SchemaKeywords::positionOf($keyword);

            if ($position === null) {
                continue;
            }

            $rule = SchemaPolarity::rule($keyword);

            if ($position === SchemaKeywords::POSITION_SCHEMA_MAP) {
                $this->compareMemberMap($keyword, $rule, $old, $new, $path, $id, $request, $changes);
            } elseif ($position === SchemaKeywords::POSITION_SCHEMA_LIST) {
                $this->compareMemberList($keyword, $rule, $old, $new, $path, $id, $request, $changes);
            } elseif ($position === SchemaKeywords::POSITION_STRING_LIST_MAP) {
                $this->compareDependentRequired($keyword, $rule, $old, $new, $path, $id, $request, $changes);
            } else {
                $this->compareSinglePosition($keyword, $rule, $old, $new, $path, $id, $request, $changes);
            }
        }
    }

    /**
     * One subschema at `$keyword`. Where the keyword's absence is a claim of its own — `not` and
     * `contains`, the two positions where no keyword and the empty schema say opposite things —
     * presence is settled first and the descent happens only where both sides carry one.
     *
     * @param  Rule  $rule
     * @param  array<string, mixed>  $old
     * @param  array<string, mixed>  $new
     * @param  list<Change>  $changes
     */
    private function compareSinglePosition(string $keyword, array $rule, array $old, array $new, string $path, string $id, bool $request, array &$changes): void
    {
        $child = $path.'.'.$keyword;
        $had = array_key_exists($keyword, $old);
        $has = array_key_exists($keyword, $new);

        if ($rule['member'] !== SchemaMember::EmptySchema && $had !== $has) {
            $changes[] = $this->presence($keyword, $rule, $has, self::keywordGates($rule, $has, $has ? $new : $old, $request), $child, $id);

            return;
        }

        foreach ($this->reclassified($this->compareSubschema($old[$keyword] ?? null, $new[$keyword] ?? null, $child, $id, $request), $rule) as $change) {
            $changes[] = $change;
        }
    }

    /**
     * A map of subschemas. `properties` has its own comparison — a member there is a property a
     * consumer reads rather than a constraint on one — and at every other map an absent member says
     * what the empty schema says, so a member arriving or leaving needs no code of its own. A `$defs`
     * member is the exception: it is a store a `$ref` may name, so presence is a claim.
     *
     * @param  Rule  $rule
     * @param  array<string, mixed>  $old
     * @param  array<string, mixed>  $new
     * @param  list<Change>  $changes
     */
    private function compareMemberMap(string $keyword, array $rule, array $old, array $new, string $path, string $id, bool $request, array &$changes): void
    {
        if ($rule['member'] === SchemaMember::Property) {
            $this->compareProperties($old, $new, $path, $id, $request, $changes);

            return;
        }

        $oldMembers = Hydrate::mapOrNull($old[$keyword] ?? null) ?? [];
        $newMembers = Hydrate::mapOrNull($new[$keyword] ?? null) ?? [];
        $child = $path.'.'.$keyword;

        foreach (Arr::sortedUnion(array_keys($oldMembers), array_keys($newMembers)) as $name) {
            $member = $child.'.'.$name;
            $had = array_key_exists($name, $oldMembers);
            $has = array_key_exists($name, $newMembers);

            if ($rule['member'] === SchemaMember::Store && $had !== $has) {
                $changes[] = $this->presence($keyword, $rule, $has, SchemaPolarity::memberPresenceIsBreaking($rule['member'], $has, $request), $member, $id);

                continue;
            }

            foreach ($this->reclassified($this->compareSubschema($oldMembers[$name] ?? null, $newMembers[$name] ?? null, $member, $id, $request), $rule) as $change) {
                $changes[] = $change;
            }
        }
    }

    /**
     * A list of subschemas — the composition keywords, plus `prefixItems`, where the INDEX is the
     * contract (index 2 constrains the third element and nothing else) so the index pairs and an
     * absent slot is an unconstrained one. Everywhere else the members pair by what they ARE
     * ({@see pairBranches()}), and the ones left over arrived or went.
     *
     * The KEYWORD arriving or leaving is settled first, exactly as at a single position, and it is a
     * different statement from a branch doing so: the side without an `anyOf` was not carrying an empty
     * union, it was carrying no union constraint at all, so every branch reading as arrived would report
     * the narrowing as the widening a branch added to an existing union is.
     *
     * @param  Rule  $rule
     * @param  array<string, mixed>  $old
     * @param  array<string, mixed>  $new
     * @param  list<Change>  $changes
     */
    private function compareMemberList(string $keyword, array $rule, array $old, array $new, string $path, string $id, bool $request, array &$changes): void
    {
        $before = self::branches($old[$keyword] ?? null);
        $after = self::branches($new[$keyword] ?? null);
        $child = $path.'.'.$keyword;
        $had = array_key_exists($keyword, $old);
        $has = array_key_exists($keyword, $new);

        if ($rule['member'] !== SchemaMember::EmptySchema && $had !== $has) {
            $changes[] = $this->presence($keyword, $rule, $has, self::keywordGates($rule, $has, $has ? $new : $old, $request), $child, $id);

            return;
        }

        if ($rule['pairsByIndex']) {
            $slots = max(count($before), count($after));

            for ($i = 0; $i < $slots; $i++) {
                foreach ($this->reclassified($this->compareSubschema($before[$i] ?? null, $after[$i] ?? null, $child.'.'.$i, $id, $request), $rule) as $change) {
                    $changes[] = $change;
                }
            }

            return;
        }

        [$pairs, $gone, $arrived] = self::pairBranches($before, $after);

        foreach ($pairs as [$i, $j]) {
            foreach ($this->reclassified($this->compareSubschema($before[$i], $after[$j], $child.'.'.$j, $id, $request), $rule) as $change) {
                $changes[] = $change;
            }
        }

        foreach ($gone as $i) {
            $changes[] = $this->presence($keyword, $rule, false, SchemaPolarity::memberPresenceIsBreaking($rule['member'], false, $request), $child.'.'.$i, $id);
        }

        foreach ($arrived as $j) {
            $changes[] = $this->presence($keyword, $rule, true, SchemaPolarity::memberPresenceIsBreaking($rule['member'], true, $request), $child.'.'.$j, $id);
        }
    }

    /**
     * `dependentRequired`: per property, the properties its presence makes required. A dependency
     * arriving or leaving is a presence question like any other, so the verdict is
     * {@see SchemaPolarity::memberPresenceIsBreaking()}'s rather than one made here.
     *
     * @param  Rule  $rule
     * @param  array<string, mixed>  $old
     * @param  array<string, mixed>  $new
     * @param  list<Change>  $changes
     */
    private function compareDependentRequired(string $keyword, array $rule, array $old, array $new, string $path, string $id, bool $request, array &$changes): void
    {
        $oldMap = Hydrate::mapOrNull($old[$keyword] ?? null) ?? [];
        $newMap = Hydrate::mapOrNull($new[$keyword] ?? null) ?? [];
        $stem = $rule['code'] ?? $keyword;

        foreach (Arr::sortedUnion(array_keys($oldMap), array_keys($newMap)) as $name) {
            $before = Hydrate::stringList($oldMap[$name] ?? null);
            $after = Hydrate::stringList($newMap[$name] ?? null);
            $member = $path.'.'.$keyword.'.'.$name;

            if (array_diff($after, $before) !== []) {
                $breaking = SchemaPolarity::memberPresenceIsBreaking($rule['member'], true, $request);
                $changes[] = $this->change(ChangeKind::Changed, $id, $member, $breaking, 'schema.'.$stem.'-added', $name, $before, $after);
            }

            if (array_diff($before, $after) !== []) {
                $breaking = SchemaPolarity::memberPresenceIsBreaking($rule['member'], false, $request);
                $changes[] = $this->change(ChangeKind::Changed, $id, $member, $breaking, 'schema.'.$stem.'-removed', $name, $before, $after);
            }
        }
    }

    /**
     * Every refinement keyword either side carries, in one sorted pass so the answer never depends on
     * which side declared what. Which way each moved is {@see SchemaRefinement}'s recorded decision; the
     * VERDICT is this class's, and it is the one the enum comparison beside it already makes. A bound
     * TIGHTENED is breaking on both sides — a request rejects a body a writer used to send, and a
     * schema's `request` flag can under-state its audience — while one RELAXED is safe for a writer and
     * hands a response reader a value it has no case for. A change nothing can order is breaking, for
     * the reason every indeterminate answer here is: a false alarm costs the author one look.
     *
     * @param  array<string, mixed>  $old
     * @param  array<string, mixed>  $new
     * @param  list<Change>  $changes
     */
    private function compareRefinements(array $old, array $new, string $path, string $id, bool $request, array &$changes): void
    {
        foreach (Arr::sortedUnion(array_keys($old), array_keys($new)) as $keyword) {
            $kind = SchemaRefinement::kindOf($keyword);

            // Not a refinement, or one with a comparison of its own — `enum`, `format`, `contentSchema`
            // and the two `contains` bounds, each named in the rule that sends it there.
            if ($kind === null || $kind === RefinementKind::Elsewhere) {
                continue;
            }

            $move = SchemaRefinement::move($keyword, $old, $new);

            if ($move === RefinementMove::Unchanged) {
                continue;
            }

            $changes[] = $this->change(
                ChangeKind::Changed,
                $id,
                $path.'.'.$keyword,
                $move !== RefinementMove::Widened || ! $request,
                'schema.refinement-'.($move === RefinementMove::Incomparable ? 'changed' : $move->value),
                $keyword,
                $old[$keyword] ?? null,
                $new[$keyword] ?? null,
            );
        }
    }

    /**
     * `minContains`/`maxContains`, the bounds on how many items `contains` has to match. Both are inert
     * with no `contains` beside them, so they are read only where both sides carry one; where `contains`
     * itself arrives or leaves, that is the whole change and {@see presence()} states it.
     *
     * @param  array<string, mixed>  $old
     * @param  array<string, mixed>  $new
     * @param  list<Change>  $changes
     */
    private function compareContainsBounds(array $old, array $new, string $path, string $id, array &$changes): void
    {
        if (! array_key_exists('contains', $old) || ! array_key_exists('contains', $new)) {
            return;
        }

        $wasAtLeast = SchemaKeywords::minContains($old);
        $isAtLeast = SchemaKeywords::minContains($new);

        if ($wasAtLeast !== $isAtLeast) {
            $changes[] = $this->containsBound($isAtLeast > $wasAtLeast, $path.'.minContains', $id, 'minContains', $wasAtLeast, $isAtLeast);
        }

        $wasCapped = SchemaKeywords::maxContains($old);
        $isCapped = SchemaKeywords::maxContains($new);

        if ($wasCapped !== $isCapped) {
            // No cap is no bound at all, so one arriving narrows however high it is set.
            $narrowed = $isCapped !== null && ($wasCapped === null || $isCapped < $wasCapped);
            $changes[] = $this->containsBound($narrowed, $path.'.maxContains', $id, 'maxContains', $wasCapped, $isCapped);
        }
    }

    /**
     * The KEYWORD arriving or leaving, where the schema that has it is the only side that can say
     * whether it asserts anything. That is `contains` and only `contains`: its own bounds are what
     * decide it, which is a fact about the schema rather than about the keyword, so the decision table
     * is handed the answer instead of reading it.
     *
     * @param  Rule  $rule
     * @param  array<string, mixed>  $carrier  the side that HAS the keyword
     */
    private static function keywordGates(array $rule, bool $arriving, array $carrier, bool $request): bool
    {
        return SchemaPolarity::keywordPresenceIsBreaking(
            $rule['member'],
            $arriving,
            $request,
            SchemaKeywords::containsAsserts($carrier),
        );
    }

    /**
     * A position, or one of its members, arriving or leaving — reported wherever that is not the same
     * statement as the empty schema arriving. The verdict is {@see SchemaPolarity}'s recorded decision,
     * settled by the caller because which of its two tables applies is the caller's question.
     *
     * @param  Rule  $rule
     */
    private function presence(string $keyword, array $rule, bool $arriving, bool $breaking, string $path, string $id): Change
    {
        return $this->change(
            $arriving ? ChangeKind::Added : ChangeKind::Removed,
            $id,
            $path,
            $breaking,
            'schema.'.($rule['code'] ?? $keyword).'-'.($arriving ? 'added' : 'removed'),
            null,
            null,
            null,
        );
    }

    /**
     * The child's changes as the parent's. At a DIRECT position they already are the parent's; anywhere
     * else the verdict is forced to breaking and each change keeps its own code and path, which
     * {@see SchemaPolarity} states in full. An annotation-only edit is the exception, moving no contract
     * at any position.
     *
     * @param  list<Change>  $changes
     * @param  Rule  $rule
     * @return list<Change>
     */
    private function reclassified(array $changes, array $rule): array
    {
        if ($rule['polarity'] === SchemaPolarity::DIRECT) {
            return $changes;
        }

        $out = [];

        foreach ($changes as $change) {
            $out[] = $change->breaking || $change->code === 'schema.annotation-changed'
                ? $change
                : new Change($change->kind, $change->target, $change->id, $change->path, true, $change->code, $change->fields);
        }

        return $out;
    }

    /** One of `contains`' own bounds, which keep a code of their own because they bound a keyword rather than the value. */
    private function containsBound(bool $narrowed, string $path, string $id, string $field, ?int $old, ?int $new): Change
    {
        return $this->change(
            ChangeKind::Changed,
            $id,
            $path,
            $narrowed,
            'schema.contains-bound-'.($narrowed ? 'narrowed' : 'widened'),
            $field,
            $old,
            $new,
        );
    }

    /**
     * One list position's members. A value that is no list is no branch at all — which is the widening
     * the canonicaliser publishes for it — so a garbage `allOf` reads as absent rather than as a branch
     * nobody wrote.
     *
     * @return list<mixed>
     */
    private static function branches(mixed $value): array
    {
        return is_array($value) && array_is_list($value) ? $value : [];
    }

    /**
     * Which of two lists' branches are the same branch. The ladder is
     * {@see ComponentNames}' rule applied to a list of branches: a member's own identity, then the
     * component it names, then its content — never its position, or reordering `oneOf` would read as
     * rewriting every branch. Where one rung matches two members on a side they pair in index order,
     * which is immaterial while the rung is what makes them equal.
     *
     * The last rung is the only inexact one, and it is why an inline branch edited in place reads as an
     * edit rather than as one branch gone and another arrived: ONE left over on each side, neither
     * naming a component, is one branch changed. Two members naming DIFFERENT components are never
     * that — a branch naming a component IS that component, so swapping the name swaps the branch, and
     * pairing them would publish `schema.ref-changed` (non-breaking) over a union branch a consumer has
     * no case for.
     *
     * @param  list<mixed>  $old
     * @param  list<mixed>  $new
     * @return array{list<array{int, int}>, list<int>, list<int>}
     */
    private static function pairBranches(array $old, array $new): array
    {
        /** @var list<callable(mixed): ?string> $rungs */
        $rungs = [self::identityKey(...), self::refKey(...), ValueKey::of(...)];

        $pairs = [];
        $oldLeft = array_keys($old);
        $newLeft = array_keys($new);

        foreach ($rungs as $rung) {
            /** @var array<string, list<int>> $waiting */
            $waiting = [];

            foreach ($newLeft as $j) {
                $key = $rung($new[$j]);

                if ($key !== null) {
                    $waiting[$key][] = $j;
                }
            }

            $unpaired = [];
            $taken = [];

            foreach ($oldLeft as $i) {
                $key = $rung($old[$i]);

                if ($key === null || ($waiting[$key] ?? []) === []) {
                    $unpaired[] = $i;

                    continue;
                }

                $j = (int) array_shift($waiting[$key]);
                $pairs[] = [$i, $j];
                $taken[$j] = true;
            }

            $oldLeft = $unpaired;
            $newLeft = array_values(array_filter($newLeft, static fn (int $j): bool => ! isset($taken[$j])));
        }

        if (count($oldLeft) === 1 && count($newLeft) === 1
            && self::refKey($old[$oldLeft[0]]) === null
            && self::refKey($new[$newLeft[0]]) === null) {
            $pairs[] = [$oldLeft[0], $newLeft[0]];
            $oldLeft = [];
            $newLeft = [];
        }

        usort($pairs, static fn (array $a, array $b): int => [$a[1], $a[0]] <=> [$b[1], $b[0]]);

        return [$pairs, $oldLeft, $newLeft];
    }

    /** The Docuccino id a branch carries — the identity every other pairing in the diff runs on. */
    private static function identityKey(mixed $member): ?string
    {
        $map = Hydrate::mapOrNull($member);
        $meta = $map === null ? null : Hydrate::mapOrNull($map['x-docuccino'] ?? null);

        return $meta === null ? null : Hydrate::stringOrNull($meta['id'] ?? null);
    }

    /** The component a branch names, which is what the branch IS. */
    private static function refKey(mixed $member): ?string
    {
        $map = Hydrate::mapOrNull($member);

        return $map === null ? null : Hydrate::stringOrNull($map['$ref'] ?? null);
    }

    private function change(ChangeKind $kind, string $id, string $path, bool $breaking, string $code, ?string $field, mixed $old, mixed $new): Change
    {
        $fields = $field === null ? [] : [new FieldChange($field, $old, $new)];

        return new Change($kind, ChangeTarget::Schema, $id, $path, $breaking, $code, $fields);
    }

    /**
     * @param  array<string, mixed>  $schema
     * @return array<string, true>
     */
    private static function typeSet(array $schema): array
    {
        $type = $schema['type'] ?? null;

        $set = [];
        if (is_string($type)) {
            $set[$type] = true;
        } elseif (is_array($type)) {
            foreach ($type as $item) {
                if (is_string($item)) {
                    $set[$item] = true;
                }
            }
        }

        return $set;
    }

    /**
     * The declared properties, each RAW: what a member is worth is {@see compareSubschema()}'s question,
     * and a reader that keeps only the members it recognises as maps drops the rest — a comparison that
     * never sees a member reports it added or removed, which is how a property declared `false` read as
     * a property that had gone.
     *
     * @param  array<string, mixed>  $schema
     * @return array<string, mixed>
     */
    private static function properties(array $schema): array
    {
        // Through `mapOrNull`, not `is_array`: `properties: {}` — no declared properties, which is what
        // an object of unknown shape publishes — arrives as a stdClass from any faithful read of an
        // artifact, and a null here would have every property read as added.
        return Hydrate::mapOrNull($schema['properties'] ?? null) ?? [];
    }

    /**
     * One subschema as the keyword map the comparisons want. `true`, an absent member and anything that
     * is no schema at all are the empty schema, which is what each of them means and publishes.
     *
     * @return array<string, mixed>
     */
    private static function asSchema(mixed $value): array
    {
        return Hydrate::mapOrNull($value) ?? [];
    }

    /**
     * @param  array<string, true>  $superset
     * @param  array<string, true>  $subset
     */
    private static function isSuperset(array $superset, array $subset): bool
    {
        foreach ($subset as $key => $_) {
            if (! isset($superset[$key])) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param  list<mixed>  $from
     * @param  list<mixed>  $against
     * @return list<mixed>
     */
    private static function valueDiff(array $from, array $against): array
    {
        $againstKeys = [];
        foreach ($against as $value) {
            $againstKeys[ValueKey::of($value)] = true;
        }

        $out = [];
        foreach ($from as $value) {
            if (! isset($againstKeys[ValueKey::of($value)])) {
                $out[] = $value;
            }
        }

        return $out;
    }
}
