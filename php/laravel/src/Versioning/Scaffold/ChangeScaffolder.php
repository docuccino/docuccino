<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Versioning\Scaffold;

use Docuccino\Attributes\Versioning\MadeRequestFieldOptional;
use Docuccino\Attributes\Versioning\MadeResponseFieldOptional;
use Docuccino\Attributes\Versioning\MadeResponseFieldRequired;
use Docuccino\Attributes\Versioning\RemovedResponseField;
use Docuccino\Attributes\Versioning\RenamedResponseField;
use Docuccino\Core\Diff\Change;
use Docuccino\Core\Diff\Changeset;
use Docuccino\Core\Diff\Pairing;
use Docuccino\Core\Document\UirDocument;
use Docuccino\Core\Support\Fqcn;
use Docuccino\Core\Support\Hydrate;
use Docuccino\Core\Support\Json;
use Docuccino\Laravel\Versioning\SchemaFacet;

/**
 * Turns a diff between the version an application published and the one its code is now into draft
 * version-change classes.
 *
 * It writes nothing it cannot say truthfully. The vocabulary expresses five differences, so those five
 * become classes and everything else becomes a SENTENCE — a wrong declaration costs the author a
 * version document that lies, while a reported gap costs them one they know is incomplete. That is the
 * degraded-answer rule at the level of a whole change.
 *
 * The one thing a diff cannot give it is the WHY, so the `description` it drafts is the diff's own
 * factual sentence rather than a `TODO`: what changed, in the words a consumer reads, with the reason
 * left for the author. Stripe's complaint was that declaring a change was separate work from making
 * it; a first draft the author improves is what makes declaring nearly free.
 *
 * @phpstan-type SchemaEntry array{name: string, body: array<string, mixed>}
 *
 * @internal
 */
final readonly class ChangeScaffolder
{
    /**
     * The one sentence for an artifact nothing can be tied back to. Identity pairing is not a nicety
     * here: a verb names the CLASS behind a schema, and the only way from a published component to that
     * class is the node id the two documents share.
     */
    private const string NO_IDENTITIES = 'The old artifact carries no Docuccino identities, so no schema in it can be tied to the class that produces it and nothing was scaffolded. Export the previous version as UIR (`docuccino:export --format=uir`) and diff against that.';

    /**
     * The change classes `$old` → `$new` calls for, and a sentence for every difference the vocabulary
     * does not reach.
     *
     * `$schemaSources` is the CURRENT build's node id → producing class map: the code is always the
     * newest version, so the class a verb names is the one the head publishes, whatever the old
     * artifact called its component.
     *
     * @param  array<string, string>  $schemaSources
     */
    public function plan(Changeset $changeset, UirDocument $old, UirDocument $new, array $schemaSources, string $since): ScaffoldPlan
    {
        if ($changeset->pairing === Pairing::Structural) {
            return new ScaffoldPlan([], [self::NO_IDENTITIES]);
        }

        $oldById = self::componentsById($old);
        $newById = self::componentsById($new);
        $classesByRef = self::classesByRef($oldById, $schemaSources);

        /** @var array<string, list<Change>> $grouped */
        $grouped = [];

        /** @var array<string, int> $gaps */
        $gaps = [];

        foreach ($changeset->changes as $change) {
            if (! isset($oldById[$change->id], $newById[$change->id])) {
                self::note($gaps, self::unexpressed($change->code));

                continue;
            }

            if (! isset($schemaSources[$change->id])) {
                self::note($gaps, sprintf('No class produces `%s`, so no verb can name it: the schema was hoisted from a shape rather than from a class of its own.', $newById[$change->id]['name']));

                continue;
            }

            $grouped[$change->id][] = $change;
        }

        $changes = [];

        foreach ($grouped as $id => $group) {
            foreach ($this->forSchema($group, $schemaSources[$id], $oldById[$id], $newById[$id], $classesByRef, $since, $gaps) as $change) {
                $changes[] = $change;
            }
        }

        usort($changes, static fn (ScaffoldedChange $a, ScaffoldedChange $b): int => strcmp($a->class, $b->class));

        return new ScaffoldPlan($changes, self::rendered($gaps));
    }

    /**
     * The changes one component schema calls for. Everything it declines is added to `$gaps` rather
     * than dropped.
     *
     * @param  list<Change>  $group
     * @param  SchemaEntry  $old
     * @param  SchemaEntry  $new
     * @param  array<string, string>  $classesByRef
     * @param  array<string, int>  $gaps
     * @return list<ScaffoldedChange>
     */
    private function forSchema(array $group, string $publishedId, array $old, array $new, array $classesByRef, string $since, array &$gaps): array
    {
        [$fqcn, $facet] = self::facetOf($publishedId);

        if ($fqcn === null || $facet === null) {
            self::note($gaps, sprintf('`%s` publishes a shape no verb can name — a paginated envelope, or a class that pinned its own identity with #[SchemaId] — so it was left alone.', $new['name']));

            return [];
        }

        $oldProperties = self::properties($old['body']);
        $newProperties = self::properties($new['body']);

        $removed = [];
        $added = [];
        $arrived = [];
        $left = [];

        foreach ($group as $change) {
            $suffix = self::suffix($change, $new['name']);

            if ($suffix === 'required' && ($change->code === 'schema.required-added' || $change->code === 'schema.required-removed')) {
                self::requiredMoves($change, $oldProperties, $newProperties, $arrived, $left);

                continue;
            }

            $field = self::fieldOf($suffix);

            if ($change->code === 'schema.property-removed' && $field !== null && array_key_exists($field, $oldProperties) && ! array_key_exists($field, $newProperties)) {
                $removed[] = $field;

                continue;
            }

            if ($change->code === 'schema.property-added' && $field !== null && array_key_exists($field, $newProperties) && ! array_key_exists($field, $oldProperties)) {
                $added[] = $field;

                continue;
            }

            self::note($gaps, self::unexpressed($change->code));
        }

        sort($removed, SORT_STRING);
        sort($added, SORT_STRING);
        sort($arrived, SORT_STRING);
        sort($left, SORT_STRING);

        [$renames, $ambiguous] = self::renames($removed, $added, $oldProperties, $newProperties);

        $changes = [];
        $short = Fqcn::short($fqcn);

        foreach ($renames as $from => $to) {
            $changes[] = $this->rename($fqcn, $short, $facet, (string) $from, $to, $since, $gaps);
        }

        foreach ($ambiguous as $field) {
            self::note($gaps, sprintf('`%s` lost `%s` and gained a field with the same shape, and nothing here can tell which — declare the rename or the removal yourself.', $new['name'], $field));
        }

        foreach ($removed as $field) {
            if (isset($renames[$field]) || in_array($field, $ambiguous, true)) {
                continue;
            }

            $changes[] = $this->removal($fqcn, $short, $facet, $field, $old, $classesByRef, $since, $gaps);
        }

        foreach ($added as $field) {
            if (in_array($field, $renames, true) || in_array($field, $ambiguous, true)) {
                continue;
            }

            self::note($gaps, 'No verb declares a field a version ADDED: older versions simply do not publish it, which is what their documents already say.');
        }

        foreach ($arrived as $field) {
            $changes[] = $this->becameRequired($fqcn, $short, $facet, $field, $since, $gaps);
        }

        foreach ($left as $field) {
            $changes[] = $this->becameOptional($fqcn, $short, $facet, $field, $since);
        }

        return array_values(array_filter($changes));
    }

    /**
     * `#[RenamedResponseField]`. The direction is the one the whole vocabulary runs in: `to:` is the
     * name the code spells today and `from:` the one the older documents publish.
     *
     * @param  array<string, int>  $gaps
     */
    private function rename(string $fqcn, string $short, SchemaFacet $facet, string $from, string $to, string $since, array &$gaps): ?ScaffoldedChange
    {
        if ($facet !== SchemaFacet::Response) {
            self::note($gaps, 'The vocabulary has no verb for a renamed REQUEST field, so the rename in a request body was not written.');

            return null;
        }

        return new ScaffoldedChange(
            class: $short.self::studly($to).'Replaces'.self::studly($from),
            since: $since,
            description: sprintf('`%s` publishes `%s` where it published `%s`.', $short, $to, $from),
            verb: self::attribute(RenamedResponseField::class, [
                'schema' => self::reference($fqcn, RenamedResponseField::class),
                'from' => self::literal($from),
                'to' => self::literal($to),
            ]),
            imports: self::imports(RenamedResponseField::class, $fqcn),
        );
    }

    /**
     * `#[RemovedResponseField]` — the one verb whose fact is gone from the code, and the one the diff
     * can fill in completely: the shape, the required-ness and the description are all still standing
     * in the artifact the older version published.
     *
     * @param  SchemaEntry  $old
     * @param  array<string, string>  $classesByRef
     * @param  array<string, int>  $gaps
     */
    private function removal(string $fqcn, string $short, SchemaFacet $facet, string $field, array $old, array $classesByRef, string $since, array &$gaps): ?ScaffoldedChange
    {
        if ($facet !== SchemaFacet::Response) {
            self::note($gaps, 'The vocabulary has no verb for a removed REQUEST field: a request body that stopped accepting a field is not something an older document can be given back honestly.');

            return null;
        }

        $property = Hydrate::map(self::properties($old['body'])[$field] ?? null);
        $type = RemovedFieldShape::spell($property, $classesByRef);
        $required = in_array($field, Hydrate::stringList($old['body']['required'] ?? null), true);
        $description = Hydrate::stringOrNull($property['description'] ?? null) ?? '';

        $arguments = [
            'schema' => self::reference($fqcn, RemovedResponseField::class),
            'field' => self::literal($field),
        ];

        if ($type !== null) {
            $arguments['type'] = str_ends_with($type, '::class') ? $type : self::literal($type);
        }

        if ($required) {
            $arguments['required'] = 'true';
        }

        if ($description !== '') {
            $arguments['description'] = self::literal($description);
        }

        return new ScaffoldedChange(
            class: $short.'Lost'.self::studly($field),
            since: $since,
            description: sprintf('`%s` no longer includes `%s`.', $short, $field),
            verb: self::attribute(RemovedResponseField::class, $arguments),
            imports: [...self::imports(RemovedResponseField::class, $fqcn), ...self::imports(RemovedResponseField::class, RemovedFieldShape::classOf($type))],
            note: $type === null
                ? sprintf('%s: the old artifact states no shape for `%s`, so it is published unconstrained. Add `type:` if you know what it held.', $short.'Lost'.self::studly($field), $field)
                : null,
        );
    }

    /**
     * `#[MadeResponseFieldRequired]`. There is no honest request-side counterpart — a `required` entry
     * arriving NARROWS a request and moves nothing on a response, which is why the vocabulary has three
     * required-ness verbs rather than two with a flag.
     *
     * @param  array<string, int>  $gaps
     */
    private function becameRequired(string $fqcn, string $short, SchemaFacet $facet, string $field, string $since, array &$gaps): ?ScaffoldedChange
    {
        if ($facet !== SchemaFacet::Response) {
            self::note($gaps, 'A request field that BECAME required has no honest verb — the older document would have to be looser than the wire, which nothing can check — so it was not written.');

            return null;
        }

        return new ScaffoldedChange(
            class: $short.self::studly($field).'BecameRequired',
            since: $since,
            description: sprintf('`%s` now always includes `%s`.', $short, $field),
            verb: self::attribute(MadeResponseFieldRequired::class, [
                'schema' => self::reference($fqcn, MadeResponseFieldRequired::class),
                'field' => self::literal($field),
            ]),
            imports: self::imports(MadeResponseFieldRequired::class, $fqcn),
        );
    }

    /** `#[MadeResponseFieldOptional]` or `#[MadeRequestFieldOptional]` — the two directions of loosening. */
    private function becameOptional(string $fqcn, string $short, SchemaFacet $facet, string $field, string $since): ScaffoldedChange
    {
        $request = $facet === SchemaFacet::Request;
        $verb = $request ? MadeRequestFieldOptional::class : MadeResponseFieldOptional::class;

        return new ScaffoldedChange(
            class: $short.self::studly($field).($request ? 'NoLongerRequired' : 'BecameOptional'),
            since: $since,
            description: $request
                ? sprintf('`%s` no longer requires `%s`.', $short, $field)
                : sprintf('`%s` may now omit `%s`.', $short, $field),
            verb: self::attribute($verb, [
                'schema' => self::reference($fqcn, $verb),
                'field' => self::literal($field),
            ]),
            imports: self::imports($verb, $fqcn),
        );
    }

    /**
     * Which removed field is which added field under a new name: the two whose published shape is
     * IDENTICAL, and only where that pairing is unique in both directions.
     *
     * A rename is the one difference a diff cannot see directly — it reads as a removal and an addition
     * — so the shape is the only evidence there is. Two candidates means there is no evidence, and
     * guessing would rename the wrong field in every document derived from this version.
     *
     * @param  list<string>  $removed
     * @param  list<string>  $added
     * @param  array<string, mixed>  $oldProperties
     * @param  array<string, mixed>  $newProperties
     * @return array{0: array<string, string>, 1: list<string>} from => to, and the fields left ambiguous
     */
    private static function renames(array $removed, array $added, array $oldProperties, array $newProperties): array
    {
        $candidates = [];
        foreach ($removed as $from) {
            $shape = Json::stable($oldProperties[$from] ?? null);

            foreach ($added as $to) {
                if (Json::stable($newProperties[$to] ?? null) === $shape) {
                    $candidates[$from][] = $to;
                }
            }
        }

        $renames = [];
        $ambiguous = [];

        foreach ($candidates as $from => $matches) {
            // Unique in both directions: one added field wearing this shape, and this the only removed
            // field wearing it. Anything else names no single pair.
            $claimants = array_filter($candidates, static fn (array $others): bool => in_array($matches[0], $others, true));

            if (count($matches) === 1 && count($claimants) === 1) {
                $renames[(string) $from] = $matches[0];

                continue;
            }

            $ambiguous[] = (string) $from;
        }

        ksort($renames, SORT_STRING);
        sort($ambiguous, SORT_STRING);

        return [$renames, $ambiguous];
    }

    /**
     * The `required` entries that arrived and left, taken off the change the differ already computed
     * and narrowed to fields BOTH sides publish — a name that came or went with its property is the
     * removal's business, and declaring it twice would have two verbs contradicting each other.
     *
     * @param  array<string, mixed>  $oldProperties
     * @param  array<string, mixed>  $newProperties
     * @param  list<string>  $arrived
     * @param  list<string>  $left
     */
    private static function requiredMoves(Change $change, array $oldProperties, array $newProperties, array &$arrived, array &$left): void
    {
        $field = $change->fields[0] ?? null;
        if ($field === null) {
            return;
        }

        $before = Hydrate::stringList($field->old);
        $after = Hydrate::stringList($field->new);

        $both = static fn (string $name): bool => array_key_exists($name, $oldProperties) && array_key_exists($name, $newProperties);

        foreach (array_diff($after, $before) as $name) {
            if ($both($name) && ! in_array($name, $arrived, true)) {
                $arrived[] = $name;
            }
        }

        foreach (array_diff($before, $after) as $name) {
            if ($both($name) && ! in_array($name, $left, true)) {
                $left[] = $name;
            }
        }
    }

    /**
     * The class a `publishedId` names and which of its shapes, or nulls where it names neither — a
     * pinned `#[SchemaId]`, or a facet no verb reaches ({@see SchemaFacet} has two).
     *
     * @return array{0: ?string, 1: ?SchemaFacet}
     */
    private static function facetOf(string $publishedId): array
    {
        $marker = strrpos($publishedId, '#');
        $fqcn = $marker === false ? $publishedId : substr($publishedId, 0, $marker);
        $facet = $marker === false ? '' : substr($publishedId, $marker + 1);

        if (! class_exists($fqcn)) {
            return [null, null];
        }

        return match ($facet) {
            '' => [$fqcn, SchemaFacet::Response],
            'request' => [$fqcn, SchemaFacet::Request],
            default => [null, null],
        };
    }

    /**
     * Every component schema a document publishes, keyed by the node id the diff pairs on.
     *
     * @return array<string, SchemaEntry>
     */
    private static function componentsById(UirDocument $document): array
    {
        $components = Hydrate::map($document->toArray()['components'] ?? null);
        $schemas = Hydrate::map($components['schemas'] ?? null);

        $out = [];

        foreach ($schemas as $name => $body) {
            $body = Hydrate::map($body);
            $id = Hydrate::stringOrNull(Hydrate::map($body['x-docuccino'] ?? null)['id'] ?? null);

            if ($id !== null) {
                $out[$id] = ['name' => (string) $name, 'body' => $body];
            }
        }

        return $out;
    }

    /**
     * @param  array<string, mixed>  $body
     * @return array<string, mixed>
     */
    private static function properties(array $body): array
    {
        return Hydrate::map($body['properties'] ?? null);
    }

    /** What a change's path says BELOW the component it addresses, or null when it addresses it whole. */
    private static function suffix(Change $change, string $name): ?string
    {
        $prefix = 'components.schemas.'.$name.'.';

        return str_starts_with($change->path, $prefix) ? substr($change->path, strlen($prefix)) : null;
    }

    /**
     * The top-level property a suffix names, or null. A nested one (`properties.a.properties.b`) reads
     * as no field at all rather than as `a.properties.b`, because the caller checks the answer against
     * the property map — which is also what lets a field whose own name holds a dot through.
     */
    private static function fieldOf(?string $suffix): ?string
    {
        return $suffix !== null && str_starts_with($suffix, 'properties.')
            ? substr($suffix, strlen('properties.'))
            : null;
    }

    /** The sentence for a difference no verb declares, keyed by the differ's own classification. */
    private static function unexpressed(string $code): string
    {
        return sprintf('`%s`: no version-change verb declares this, so nothing was written for it.', $code);
    }

    /**
     * @param  array<string, int>  $gaps
     */
    private static function note(array &$gaps, string $sentence): void
    {
        $gaps[$sentence] = ($gaps[$sentence] ?? 0) + 1;
    }

    /**
     * The gaps as lines, sorted, each carrying how many differences it stands for — one line per
     * KIND, because a diff over a real release names hundreds and a reader who scrolls reads none.
     *
     * @param  array<string, int>  $gaps
     * @return list<string>
     */
    private static function rendered(array $gaps): array
    {
        ksort($gaps, SORT_STRING);

        $lines = [];
        foreach ($gaps as $sentence => $count) {
            $lines[] = $count === 1 ? $sentence : sprintf('%s (×%d)', $sentence, $count);
        }

        return $lines;
    }

    /**
     * The FQCNs one change's file imports: the verb, and the classes it names — each dropped where it
     * shorts to the verb's own name and is therefore written out in full ({@see reference()}).
     *
     * @return list<string>
     */
    private static function imports(string $verb, ?string ...$named): array
    {
        $imports = [$verb];

        foreach ($named as $fqcn) {
            if ($fqcn !== null && Fqcn::short($fqcn) !== Fqcn::short($verb)) {
                $imports[] = $fqcn;
            }
        }

        return $imports;
    }

    /**
     * Which `$ref` pointer of the OLD document names which class, so a removed field that held one can
     * be re-added as a pointer at it. Read off the HEAD's sources: a component the head no longer
     * publishes cannot be pointed at, and this is where that comes out as "no class".
     *
     * @param  array<string, SchemaEntry>  $oldById
     * @param  array<string, string>  $schemaSources
     * @return array<string, string>
     */
    private static function classesByRef(array $oldById, array $schemaSources): array
    {
        $out = [];

        foreach ($oldById as $id => $entry) {
            $source = $schemaSources[$id] ?? null;

            if ($source !== null && class_exists($source)) {
                $out['#/components/schemas/'.$entry['name']] = $source;
            }
        }

        return $out;
    }

    /**
     * How a verb spells the class it names: the short name, which is what the file imports and what an
     * author would have written — unless the class shorts to the same word as the verb itself, where
     * two imports of one name is a compile error and the file would never load at all.
     */
    private static function reference(string $fqcn, string $verb): string
    {
        return Fqcn::short($fqcn) === Fqcn::short($verb)
            ? '\\'.ltrim($fqcn, '\\').'::class'
            : Fqcn::short($fqcn).'::class';
    }

    /**
     * One attribute, rendered with named arguments in the order given. Named because the vocabulary's
     * one invited mistake is writing `from:` and `to:` the wrong way round, and a positional pair says
     * nothing to the reader about which end is which.
     *
     * @param  array<string, string>  $arguments  argument name => the PHP it is written as
     */
    private static function attribute(string $verb, array $arguments): string
    {
        $written = [];
        foreach ($arguments as $name => $value) {
            $written[] = $name.': '.$value;
        }

        return sprintf('#[%s(%s)]', Fqcn::short($verb), implode(', ', $written));
    }

    /** A PHP single-quoted literal. The values are field names and prose off an artifact nobody parsed. */
    private static function literal(string $value): string
    {
        return "'".str_replace(['\\', "'"], ['\\\\', "\\'"], $value)."'";
    }

    /**
     * A field name as a class-name segment. Deterministic and lossy on purpose: two field names that
     * studly alike would collide, and the collision is refused where the file is written rather than
     * papered over with a counter — {@see Arr} is not the shape of a name.
     */
    private static function studly(string $field): string
    {
        $words = preg_split('/[^A-Za-z0-9]+/', $field) ?: [];

        $studly = '';
        foreach ($words as $word) {
            $studly .= ucfirst($word);
        }

        return $studly === '' ? 'Field' : $studly;
    }
}
