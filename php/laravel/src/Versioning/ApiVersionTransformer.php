<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Versioning;

use Docuccino\Attributes\Versioning\RenamedResponseField;
use Docuccino\Core\Diagnostics\Diagnostic;
use Docuccino\Core\Diagnostics\Severity;
use Docuccino\Core\Document\PathItem;
use Docuccino\Core\Extensions\Context\DocumentContext;
use Docuccino\Core\Extensions\Context\RepresentationPolicy;
use Docuccino\Core\Extensions\Contracts\DocumentTransformer;
use Docuccino\Core\Extensions\Document\UirDocumentDraft;
use Docuccino\Core\Extensions\Ordering\ExtensionOrder;
use Docuccino\Core\Extensions\Ordering\Priorities;
use Docuccino\Core\Extensions\Schema\EnumDecoration;
use Docuccino\Core\Identity\IdentityGenerator;
use Docuccino\Core\Support\PlainText;
use Docuccino\Laravel\Config\ConfiguredDocuments;
use Docuccino\Laravel\Support\ListValueNames;

/**
 * Turns the document a build just assembled into the document for the API version it declares:
 * `info.version` becomes that version, every declared change that shipped AFTER it is applied in
 * REVERSE, and every operation declares the header a client pins a version with.
 *
 * A document with no `api_version` is not an API version, and this moves not a byte of it.
 *
 * This is not the "patch a canonical document" the design refuses. That refusal is about emitting N
 * patched copies of ONE build, and about Overlay's merge semantics being able only to widen. Here each
 * version is its own `DocumentGenerator::generate()` run — a pure function of (code, version) — and
 * this runs inside that run, before content, the final component ordering and the content hash. What it
 * writes is canonicalised, linted and hashed exactly like anything a producer wrote.
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
        $version = $config->apiVersion();
        if ($version === null) {
            return;
        }

        [$changes, $diagnostics] = $this->changes->collect($config);
        foreach ($diagnostics as $diagnostic) {
            $context->report($diagnostic);
        }

        $doc = $document->toArray();

        // The document states its own version once. `info.version` is derived from `api_version.version`
        // rather than written a second time, so the two cannot drift apart.
        $info = is_array($doc['info'] ?? null) ? $doc['info'] : [];
        $info['version'] = $version;
        $doc['info'] = $info;

        // The code is the newest version, so an older document is the code with every LATER change
        // undone — newest first, each handing the shape of the version below it to the next.
        foreach ($changes as $change) {
            if (strcmp($change->since, $version) <= 0) {
                continue;
            }

            foreach ($change->renames as $rename) {
                $doc = $this->applyRename($doc, $rename, $change, $context);
            }
        }

        $document->replace($this->declareVersionHeader($doc, $context, $version, $changes));
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

        if ($outcome === 'renamed') {
            return $doc;
        }

        if ($outcome === 'taken') {
            $context->report(VersionChangeCollector::unapplicable($change->class, sprintf(
                'the schema for %s already publishes a field called "%s", so renaming "%s" onto it would collapse two fields into one',
                PlainText::of($rename->schema),
                PlainText::of($rename->from),
                PlainText::of($rename->to),
            )));

            return $doc;
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

        return $doc;
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
