<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Versioning;

use Docuccino\Attributes\Versioning\RenamedResponseField;
use Docuccino\Core\Diagnostics\Diagnostic;
use Docuccino\Core\Diagnostics\Severity;
use Docuccino\Core\Document\PathItem;
use Docuccino\Core\Extensions\Context\DocumentConfig;
use Docuccino\Core\Extensions\Context\DocumentContext;
use Docuccino\Core\Extensions\Context\RepresentationPolicy;
use Docuccino\Core\Extensions\Contracts\DocumentTransformer;
use Docuccino\Core\Extensions\Document\UirDocumentDraft;
use Docuccino\Core\Extensions\Ordering\ExtensionOrder;
use Docuccino\Core\Extensions\Ordering\Priorities;
use Docuccino\Core\Extensions\Schema\EnumDecoration;
use Docuccino\Core\Identity\IdentityGenerator;
use Docuccino\Core\Lint\LintSafelist;
use Docuccino\Core\Support\Hydrate;
use Docuccino\Core\Support\PlainText;
use Docuccino\Laravel\Config\ConfiguredDocuments;
use Docuccino\Laravel\Support\ListValueNames;

/**
 * Turns the document a build just assembled into the document for the API version it declares: every
 * declared change that shipped AFTER this version is applied in REVERSE, and every operation declares
 * the header a client pins a version with.
 *
 * A document with no `api_version` is not an API version, and this moves not a byte of it.
 *
 * This is not the "patch a canonical document" the design refuses. That refusal is about emitting N
 * patched copies of ONE build, and about Overlay's merge semantics being able only to widen. Here each
 * version is its own `DocumentGenerator::generate()` run — a pure function of (code, version) — and
 * this runs inside that run, before content, the final component ordering and the content hash. What it
 * writes is canonicalised, linted and hashed exactly like anything a producer wrote.
 *
 * ## Why a scoped change INLINES rather than mints a name
 *
 * A change scoped with `#[AppliesTo]` to some of the operations that publish a schema means those
 * operations genuinely have a different type from the others, so the shared component has to fork. The
 * fork is an INLINE schema at the operation, never a second component called `FormDataV2`. A published
 * component name becomes a type name in somebody's generated client, and `ComponentNames`' invariant is
 * that a name is a function of the things contesting it — a name that appeared or vanished depending on
 * how many operations happened to share a body would be a function of the route table instead, so an
 * unrelated new endpoint would rename a type. An inline schema registers no name, so it cannot.
 *
 * And when the scope covers EVERY operation that publishes the schema there is no fork at all: the
 * component is renamed in place, byte for byte what an unscoped change produces. Without that, scoping
 * to all of them would emit something different from scoping to none of them, which is the same fact
 * said twice in two shapes.
 *
 * @phpstan-type OperationSite array{keys: list<string>, signature: string|null, operationId: string|null}
 *
 * @internal
 */
#[ExtensionOrder(priority: Priorities::LATE)]
final readonly class ApiVersionTransformer implements DocumentTransformer
{
    /** The one document member a rename has to keep in step with `properties`. */
    private const REQUIRED = 'required';

    public function __construct(
        private VersionChangeCollector $changes,
        private ConfiguredDocuments $documents = new ConfiguredDocuments,
        private IdentityGenerator $identity = new IdentityGenerator,
    ) {}

    public function transform(UirDocumentDraft $document, DocumentContext $context): void
    {
        $config = $context->config;
        if (! $config->declaresApiVersion()) {
            return;
        }

        $version = $config->apiVersion();
        if ($version === null) {
            // Publishing the placeholder would put a version the application does not serve into every
            // operation's enum AND make it the default a client falls back to, which is worse than
            // publishing no version at all. So this document stays exactly what it was.
            $context->report(new Diagnostic(
                severity: Severity::Warning,
                code: 'versioning.version-unstated',
                message: sprintf(
                    'The "%s" document declares api_version but its info.version is still the placeholder "%s", so it was not derived as an API version.',
                    PlainText::of($config->key),
                    DocumentConfig::DEFAULT_VERSION,
                ),
                help: sprintf(
                    'Set documents.%s.info.version to the version this document describes — that value IS the API version.',
                    PlainText::of($config->key),
                ),
            ));

            return;
        }

        $set = $this->changes->collect($config);
        foreach ($set->diagnostics as $diagnostic) {
            $context->report($diagnostic);
        }

        $doc = $document->toArray();

        // The code is the newest version, so an older document is the code with every LATER change
        // undone — newest first, each handing the shape of the version below it to the next.
        foreach ($set->after($version) as $change) {
            foreach ($change->renames as $rename) {
                $doc = $change->selectors === []
                    ? $this->applyRename($doc, $rename, $change, $context)
                    : $this->applyScopedRename($doc, $rename, $change, $context);
            }
        }

        $document->replace($this->declareVersionHeader($doc, $context, $version, $set->changes));
    }

    /**
     * Renames one response field back to what versions before the change published, wherever the
     * document publishes that schema — the hoisted component and any inline copy of it alike, matched
     * by the schema's own identity rather than by the property name, so a `title` on an unrelated
     * schema is never touched.
     *
     * @param  array<string, mixed>  $doc
     * @return array<string, mixed>
     */
    private function applyRename(array $doc, RenamedResponseField $rename, VersionChange $change, DocumentContext $context): array
    {
        $id = $this->identity->namedSchemaId(ltrim($rename->schema, '\\'));

        $outcome = 'unresolved';

        // From the members rather than the root: the root's own `x-docuccino` describes the document,
        // and no schema's identity can be there.
        foreach ($doc as $key => $value) {
            if (is_array($value)) {
                $doc[$key] = $this->rewrite($value, $id, $rename, $outcome);
            }
        }

        $this->reportOutcome($outcome, $rename, $change, $context);

        return $doc;
    }

    /**
     * Applies a change that `#[AppliesTo]` narrows to some operations. The fork rule, in order:
     *
     *  1. the operations this document publishes the schema for are computed, following `$ref`s;
     *  2. a selector that names none of them is reported and decides nothing;
     *  3. a scope covering all of them renames the shared component in place — no fork;
     *  4. otherwise the matched operations get the older shape inlined, and the rest keep the shared
     *     component exactly as the head document has it.
     *
     * @param  array<string, mixed>  $doc
     * @return array<string, mixed>
     */
    private function applyScopedRename(array $doc, RenamedResponseField $rename, VersionChange $change, DocumentContext $context): array
    {
        $id = $this->identity->namedSchemaId(ltrim($rename->schema, '\\'));
        $reaches = self::componentsReaching($doc, $id);

        $reaching = [];
        foreach (self::operationSites($doc) as $index => $site) {
            $operation = self::at($doc, $site['keys']);
            if (is_array($operation) && self::nodeReaches($operation, $id, $reaches)) {
                $reaching[$index] = $site;
            }
        }

        if ($reaching === []) {
            // Nothing this document publishes for an operation carries the schema, so whatever is wrong
            // is wrong about the SCHEMA and not about the scope. The unscoped path is what says which.
            return $this->applyRename($doc, $rename, $change, $context);
        }

        $matched = [];
        foreach ($reaching as $index => $site) {
            // The one reader of a selector in this product. A raw comparison here would be a second
            // grammar: this one canonicalises a `#/…` pointer and globs by the wildcard the route filters
            // glob by, and a scope reading either differently is a config entry that means one thing to
            // the author and another to the build.
            if (LintSafelist::matches($change->selectors, $site['signature'], $site['operationId'])) {
                $matched[$index] = true;
            }
        }

        foreach ($change->selectors as $selector) {
            if (! self::namesAny([$selector], $reaching)) {
                $context->report(self::matchesNothing($change, $selector, $rename));
            }
        }

        if ($matched === []) {
            return $doc;
        }

        if (count($matched) === count($reaching)) {
            return $this->applyRename($doc, $rename, $change, $context);
        }

        foreach (array_keys($matched) as $index) {
            $doc = $this->fork($doc, $reaching[$index], $id, $rename, $reaches, $change, $context);
        }

        return $doc;
    }

    /**
     * Whether any of these operations goes by one of the entries — the same reading, asked of one entry
     * at a time, so a selector that decided nothing can be named on its own.
     *
     * @param  list<string>  $entries
     * @param  array<int, OperationSite>  $sites
     */
    private static function namesAny(array $entries, array $sites): bool
    {
        foreach ($sites as $site) {
            if (LintSafelist::matches($entries, $site['signature'], $site['operationId'])) {
                return true;
            }
        }

        return false;
    }

    /**
     * Gives one operation its own copy of the schema, renamed, leaving the shared component for
     * everybody else. Every `$ref` on the way down to the schema is expanded, because a copy still
     * pointing at the shared component would be the shared component.
     *
     * @param  array<string, mixed>  $doc
     * @param  OperationSite  $site
     * @param  array<string, bool>  $reaches
     * @return array<string, mixed>
     */
    private function fork(array $doc, array $site, string $id, RenamedResponseField $rename, array $reaches, VersionChange $change, DocumentContext $context): array
    {
        $operation = self::at($doc, $site['keys']);
        if (! is_array($operation)) {
            return $doc;
        }

        $outcome = 'unresolved';
        $cyclic = false;
        $forked = $this->inline($operation, $doc, $id, $rename, $reaches, [], $outcome, $cyclic);

        if ($cyclic) {
            // A schema that refers to itself cannot be given a private copy: the copy would contain the
            // shared component again, and the operation would publish the old name at one depth and the
            // new one at the next. The head shape is at least a shape that exists.
            $context->report(VersionChangeCollector::unapplicable($change->class, sprintf(
                'the schema for %s refers to itself, so the operation "%s" cannot be given a copy of it and was left at the shape the code publishes',
                PlainText::of($rename->schema),
                PlainText::of($site['signature'] ?? implode('/', $site['keys'])),
            )));

            return $doc;
        }

        if ($outcome !== 'renamed') {
            $this->reportOutcome($outcome, $rename, $change, $context);

            return $doc;
        }

        return self::with($doc, $site['keys'], $forked);
    }

    /**
     * @param  array<array-key, mixed>  $node
     * @param  array<string, mixed>  $doc
     * @param  array<string, bool>  $reaches
     * @param  list<string>  $visited
     * @return array<array-key, mixed>
     */
    private function inline(array $node, array $doc, string $id, RenamedResponseField $rename, array $reaches, array $visited, string &$outcome, bool &$cyclic): array
    {
        $ref = self::componentRef($node);
        if ($ref !== null && ($reaches[$ref] ?? false)) {
            if (in_array($ref, $visited, true)) {
                $cyclic = true;

                return $node;
            }

            $body = self::componentBody($doc, $ref);
            if ($body === null) {
                return $node;
            }

            $expanded = $this->inline($body, $doc, $id, $rename, $reaches, [...$visited, $ref], $outcome, $cyclic);

            // OAS 3.1 lets a `$ref` carry siblings, and they annotate what they point at, so they win
            // over the body they are written beside.
            unset($node['$ref']);

            return [...$expanded, ...$node];
        }

        $docuccino = $node['x-docuccino'] ?? null;
        if (is_array($docuccino) && ($docuccino['id'] ?? null) === $id) {
            $node = $this->rewriteSchema($node, $rename, $outcome);
        }

        foreach ($node as $key => $value) {
            if (is_array($value)) {
                $node[$key] = $this->inline($value, $doc, $id, $rename, $reaches, $visited, $outcome, $cyclic);
            }
        }

        return $node;
    }

    /**
     * Walks every node, rewriting the ones carrying `$id`. `$outcome` is the strongest thing seen:
     * `renamed` beats `taken` beats `absent` beats `unresolved`, so several copies of one schema report
     * one answer and a document that publishes it nowhere reports that instead.
     *
     * @param  array<array-key, mixed>  $node
     * @return array<array-key, mixed>
     */
    private function rewrite(array $node, string $id, RenamedResponseField $rename, string &$outcome): array
    {
        $docuccino = $node['x-docuccino'] ?? null;
        if (is_array($docuccino) && ($docuccino['id'] ?? null) === $id) {
            $node = $this->rewriteSchema($node, $rename, $outcome);
        }

        foreach ($node as $key => $value) {
            if (is_array($value)) {
                $node[$key] = $this->rewrite($value, $id, $rename, $outcome);
            }
        }

        return $node;
    }

    /**
     * @param  array<array-key, mixed>  $schema
     * @return array<array-key, mixed>
     */
    private function rewriteSchema(array $schema, RenamedResponseField $rename, string &$outcome): array
    {
        $properties = $schema['properties'] ?? null;
        if (! is_array($properties) || ! array_key_exists($rename->to, $properties)) {
            $outcome = $outcome === 'renamed' || $outcome === 'taken' ? $outcome : 'absent';

            return $schema;
        }

        if (array_key_exists($rename->from, $properties)) {
            $outcome = $outcome === 'renamed' ? $outcome : 'taken';

            return $schema;
        }

        // In place, so the property keeps its position and everything it carries — provenance included,
        // which travels with the node rather than being re-keyed beside it.
        $renamed = [];
        foreach ($properties as $name => $value) {
            $renamed[$name === $rename->to ? $rename->from : $name] = $value;
        }
        $schema['properties'] = $renamed;

        // Load-bearing: a required list still naming today's field would mark a body carrying the OLD
        // name invalid, and a body carrying the new one valid — the exact disagreement a per-version
        // contract test exists to catch.
        $required = $schema[self::REQUIRED] ?? null;
        if (is_array($required)) {
            $schema[self::REQUIRED] = array_values(array_map(
                static fn (mixed $name): mixed => $name === $rename->to ? $rename->from : $name,
                $required,
            ));
        }

        $outcome = 'renamed';

        return $schema;
    }

    /** What a rename that did not happen has to say for itself. `renamed` says nothing. */
    private function reportOutcome(string $outcome, RenamedResponseField $rename, VersionChange $change, DocumentContext $context): void
    {
        if ($outcome === 'renamed') {
            return;
        }

        if ($outcome === 'taken') {
            $context->report(VersionChangeCollector::unapplicable($change->class, sprintf(
                'the schema for %s already publishes a field called "%s", so renaming "%s" onto it would collapse two fields into one',
                PlainText::of($rename->schema),
                PlainText::of($rename->from),
                PlainText::of($rename->to),
            )));

            return;
        }

        $context->report($outcome === 'absent'
            ? new Diagnostic(
                severity: Severity::Warning,
                code: 'versioning.change-target-missing',
                message: sprintf(
                    '%s renames "%s", which the schema for %s no longer publishes, so this version still says what the code says.',
                    $change->class,
                    PlainText::of($rename->to),
                    PlainText::of($rename->schema),
                ),
                help: 'Update the change to name the field as it is spelled today, or retire it if the field is gone.',
            )
            : new Diagnostic(
                severity: Severity::Warning,
                code: 'versioning.schema-unresolved',
                message: sprintf(
                    '%s names %s, which this document publishes no schema for, so the change was skipped and this version is left at the current shape.',
                    $change->class,
                    PlainText::of($rename->schema),
                ),
                help: 'Name the class whose shape the document actually publishes — a change can only rewrite a schema this document contains.',
            ));
    }

    /**
     * A selector naming no operation this document publishes the schema for. Worth a warning because a
     * scope that matches nothing is indistinguishable from a change that was never declared: a route
     * renamed months later silently stops the change applying, and the version's document goes back to
     * saying what the code says without anything having been edited.
     */
    private static function matchesNothing(VersionChange $change, string $selector, RenamedResponseField $rename): Diagnostic
    {
        return new Diagnostic(
            severity: Severity::Warning,
            code: 'versioning.scope-matches-nothing',
            message: sprintf(
                '%s is scoped to "%s", which names no operation this document publishes %s for, so that part of the change applies to nothing.',
                $change->class,
                PlainText::of($selector),
                PlainText::of($rename->schema),
            ),
            help: 'Write the operation the way the document names it — `GET /api/things`, an operationId, or either with a `*` — and check the document publishes that schema for it.',
        );
    }

    /**
     * Every operation this document publishes, with the names a selector may call it by. A webhook is
     * indexed too — a schema it carries is a place the shape appears, so a scope that leaves it out has
     * to fork — but it has no signature: a webhook is a request the SERVER makes, and no client pins it.
     *
     * @param  array<string, mixed>  $doc
     * @return list<OperationSite>
     */
    private static function operationSites(array $doc): array
    {
        $sites = [];

        foreach (['paths', 'webhooks'] as $section) {
            $items = $doc[$section] ?? null;
            if (! is_array($items)) {
                continue;
            }

            foreach ($items as $name => $item) {
                if (! is_array($item)) {
                    continue;
                }

                foreach (PathItem::METHODS as $method) {
                    $operation = $item[$method] ?? null;
                    if (! is_array($operation)) {
                        continue;
                    }

                    $operationId = $operation['operationId'] ?? null;

                    $sites[] = [
                        'keys' => [$section, (string) $name, $method],
                        'signature' => $section === 'paths' ? strtoupper($method).' '.$name : null,
                        'operationId' => is_string($operationId) ? $operationId : null,
                    ];
                }
            }
        }

        return $sites;
    }

    /**
     * Which components carry the schema, following component-to-component `$ref`s to a fixpoint. Read
     * once per rename rather than walked per operation, and to a fixpoint rather than recursively,
     * because a schema that refers to itself is a legal document and an unbounded walk of one is not.
     *
     * @param  array<string, mixed>  $doc
     * @return array<string, bool>
     */
    private static function componentsReaching(array $doc, string $id): array
    {
        $components = $doc['components'] ?? null;
        if (! is_array($components)) {
            return [];
        }

        $reaches = [];
        $refs = [];
        foreach ($components as $section => $members) {
            if (! is_array($members)) {
                continue;
            }

            foreach ($members as $name => $body) {
                $pointer = '#/components/'.$section.'/'.$name;
                $reaches[$pointer] = is_array($body) && self::nodeReaches($body, $id, []);
                $refs[$pointer] = is_array($body) ? self::refsIn($body) : [];
            }
        }

        do {
            $changed = false;
            foreach ($refs as $pointer => $targets) {
                if ($reaches[$pointer]) {
                    continue;
                }

                foreach ($targets as $target) {
                    if ($reaches[$target] ?? false) {
                        $reaches[$pointer] = true;
                        $changed = true;

                        break;
                    }
                }
            }
        } while ($changed);

        return $reaches;
    }

    /**
     * Whether a node carries the schema itself, or a `$ref` to a component that does.
     *
     * @param  array<array-key, mixed>  $node
     * @param  array<string, bool>  $reaches
     */
    private static function nodeReaches(array $node, string $id, array $reaches): bool
    {
        $docuccino = $node['x-docuccino'] ?? null;
        if (is_array($docuccino) && ($docuccino['id'] ?? null) === $id) {
            return true;
        }

        $ref = self::componentRef($node);
        if ($ref !== null && ($reaches[$ref] ?? false)) {
            return true;
        }

        foreach ($node as $value) {
            if (is_array($value) && self::nodeReaches($value, $id, $reaches)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Every `#/components/…` pointer a node names, at any depth.
     *
     * @param  array<array-key, mixed>  $node
     * @return list<string>
     */
    private static function refsIn(array $node): array
    {
        $refs = [];

        $ref = self::componentRef($node);
        if ($ref !== null) {
            $refs[] = $ref;
        }

        foreach ($node as $value) {
            if (is_array($value)) {
                $refs = [...$refs, ...self::refsIn($value)];
            }
        }

        return $refs;
    }

    /**
     * The component this node is a `$ref` to, or null when it is not one. Only an in-document
     * `#/components/<section>/<name>` counts: an external `$ref` names a file this build never read.
     *
     * @param  array<array-key, mixed>  $node
     */
    private static function componentRef(array $node): ?string
    {
        $ref = $node['$ref'] ?? null;

        return is_string($ref) && preg_match('#^\#/components/[^/]+/[^/]+$#', $ref) === 1 ? $ref : null;
    }

    /**
     * @param  array<string, mixed>  $doc
     * @return array<array-key, mixed>|null
     */
    private static function componentBody(array $doc, string $pointer): ?array
    {
        $parts = explode('/', substr($pointer, 2));
        $body = self::at($doc, $parts);

        return is_array($body) ? $body : null;
    }

    /**
     * @param  array<array-key, mixed>  $node
     * @param  list<string>  $keys
     */
    private static function at(array $node, array $keys): mixed
    {
        foreach ($keys as $key) {
            $next = $node[$key] ?? null;
            if (! is_array($next)) {
                return $next;
            }

            $node = $next;
        }

        return $node;
    }

    /**
     * @param  array<string, mixed>  $node
     * @param  list<string>  $keys
     * @return array<string, mixed>
     */
    private static function with(array $node, array $keys, mixed $value): array
    {
        $key = array_shift($keys);
        if ($key === null) {
            return $node;
        }

        if ($keys === []) {
            $node[$key] = $value;

            return $node;
        }

        $node[$key] = self::with(Hydrate::map($node[$key] ?? null), $keys, $value);

        return $node;
    }

    /**
     * Declares the version header on every operation the document publishes: `in: header`, optional,
     * defaulting to this document's version and enumerating every version the application configures.
     *
     * The enum is derived from the document SET rather than from a second list kept beside it, so
     * adding a version moves the enum in every other version document. That is correct and deliberate —
     * versions are related by construction, so this is not the locality rule being broken.
     *
     * `webhooks` are left alone: a webhook is a request the SERVER makes, and the header is what a
     * CLIENT sends to pin a version.
     *
     * @param  array<string, mixed>  $doc
     * @param  list<VersionChange>  $changes
     * @return array<string, mixed>
     */
    private function declareVersionHeader(array $doc, DocumentContext $context, string $version, array $changes): array
    {
        $name = $context->config->apiVersionHeader();
        $schema = $this->versionSchema($context, $version, $changes);

        $paths = $doc['paths'] ?? null;
        if (! is_array($paths)) {
            return $doc;
        }

        foreach ($paths as $path => $item) {
            if (! is_array($item)) {
                continue;
            }

            foreach (PathItem::METHODS as $method) {
                $operation = $item[$method] ?? null;
                if (is_array($operation)) {
                    $item[$method] = $this->withVersionHeader($operation, $name, $schema);
                }
            }

            $paths[$path] = $item;
        }

        $doc['paths'] = $paths;

        return $doc;
    }

    /**
     * @param  array<array-key, mixed>  $operation
     * @param  array<string, mixed>  $schema
     * @return array<array-key, mixed>
     */
    private function withVersionHeader(array $operation, string $name, array $schema): array
    {
        $parameters = $operation['parameters'] ?? null;
        $parameters = is_array($parameters) ? array_values($parameters) : [];

        foreach ($parameters as $parameter) {
            $declared = is_array($parameter) ? $parameter['name'] ?? null : null;

            // An application that documents the header itself keeps its own wording; two parameters of
            // one name in one location is a document no client can read.
            if (is_array($parameter) && ($parameter['in'] ?? null) === 'header' && is_string($declared) && strcasecmp($declared, $name) === 0) {
                return $operation;
            }
        }

        $parameter = [
            'name' => $name,
            'in' => 'header',
            'description' => 'The API version this request is answered as. Omit it and the request is answered as the version this document describes.',
            'required' => false,
            'schema' => $schema,
        ];

        $docuccino = $operation['x-docuccino'] ?? null;
        $operationId = is_array($docuccino) ? $docuccino['id'] ?? null : null;
        if (is_string($operationId)) {
            $parameter = ['x-docuccino' => ['id' => $this->identity->parameterId($operationId, 'header', $name)], ...$parameter];
        }

        $parameters[] = $parameter;
        $operation['parameters'] = $parameters;

        return $operation;
    }

    /**
     * The closed set of versions, decorated the way every other published enum is: SDK member names for
     * values no generator could name a constant after (`2026-09-01` is not an identifier), and the
     * change each version shipped as its per-value prose.
     *
     * @param  list<VersionChange>  $changes
     * @return array<string, mixed>
     */
    private function versionSchema(DocumentContext $context, string $version, array $changes): array
    {
        $versions = $this->documents->apiVersions();

        // An enum narrower than what the server accepts is worse than none: it marks a working request
        // invalid. This document's own version is one the server certainly answers, whatever the
        // configured set turned out to hold, so it is in the set or the set is wrong.
        if (! in_array($version, $versions, true)) {
            $versions[] = $version;
            sort($versions, SORT_STRING);
        }

        $policy = RepresentationPolicy::fromConfig($context->config->representation);

        return EnumDecoration::apply(
            ['type' => 'string', 'enum' => $versions, 'default' => $version],
            $policy->enumNaming,
            ListValueNames::names($versions),
            self::changeProse($changes),
        );
    }

    /**
     * What each version changed, keyed by the version it shipped in — the descriptions the changes
     * themselves carry, joined in the order the collector settled, so a version that shipped two
     * changes reads as one sentence per change and never depends on which file was met first.
     *
     * @param  list<VersionChange>  $changes
     * @return array<string, string>
     */
    private static function changeProse(array $changes): array
    {
        $prose = [];
        foreach ($changes as $change) {
            if ($change->description !== '') {
                $prose[$change->since][] = $change->description;
            }
        }

        return array_map(static fn (array $lines): string => implode(' ', $lines), $prose);
    }
}
