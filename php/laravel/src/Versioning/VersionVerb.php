<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Versioning;

use Docuccino\Core\Diagnostics\Diagnostic;
use Docuccino\Core\Identity\IdentityGenerator;

/**
 * One verb of a declared version change, as {@see ApiVersionTransformer} applies it: the node it
 * names, the edit it makes to that node, what its examples have to do to follow, and what it says
 * when it could not be applied.
 *
 * A verb is written as an attribute in the dependency-free attributes package, which can implement
 * nothing; these are the adapter-side readings of those declarations, and {@see VerbOrder} is where
 * one change's are put in the order they apply.
 *
 * @internal
 */
interface VersionVerb
{
    /** The class the declaration names, as it is written. */
    public function schema(): string;

    /** Which of the class's published shapes it names. */
    public function facet(): SchemaFacet;

    /** The identity of the node this verb edits — a class's response shape and its request one differ. */
    public function identity(IdentityGenerator $identity): string;

    /**
     * The edit, on a node carrying {@see identity()}. `$outcome` accumulates the strongest thing seen
     * across every node the walk reaches, so an implementation only ever raises it.
     *
     * @param  array<array-key, mixed>  $schema
     * @return array<array-key, mixed>
     */
    public function apply(array $schema, VerbOutcome &$outcome): array;

    /**
     * The examples the whole document publishes, walked with the schema, and the reports owed for the
     * ones that could not follow. A verb that moves no key returns the document untouched: an example
     * whose members all keep their names is already the shape this version publishes.
     *
     * @param  array<string, mixed>  $doc
     * @return array{0: array<string, mixed>, 1: list<Diagnostic>}
     */
    public function rewriteDocumentExamples(array $doc, string $id, VersionChange $change): array;

    /**
     * The same over ONE operation, for a scoped change giving it a private copy of the schema.
     * `$keys` is where the operation stands, so a dropped example names its own pointer.
     *
     * @param  array<array-key, mixed>  $operation
     * @param  array<string, mixed>  $doc
     * @param  list<string>  $keys
     * @return array{0: array<array-key, mixed>, 1: list<Diagnostic>}
     */
    public function rewriteOperationExamples(array $operation, array $doc, string $id, array $keys, VersionChange $change): array;

    /** What an outcome has to say for itself. An applied verb says nothing. */
    public function diagnose(VerbOutcome $outcome, VersionChange $change): ?Diagnostic;
}
