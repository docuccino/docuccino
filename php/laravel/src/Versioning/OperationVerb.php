<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Versioning;

use Docuccino\Core\Diagnostics\Diagnostic;
use Docuccino\Core\Identity\IdentityGenerator;

/**
 * A verb whose subject is an OPERATION rather than a schema, as {@see ApiVersionTransformer} applies it.
 *
 * The distinction is not a tidying: a {@see VersionVerb} names a class, resolves it to ONE node
 * identity, and is applied wherever the document carries that identity — the walk expands `$ref`s and
 * forks a shared component where a scope narrows it. A parameter has none of that. It is flattened onto
 * `operation.parameters[]` under an identity that is a function of the operation AND of the parameter's
 * own name, so there is no single node to look for, nothing shared to fork, and the name itself is part
 * of what identifies the thing being renamed.
 *
 * That is also why `#[AppliesTo]` degrades to a plain filter for these. A schema verb's scope has two
 * branches — narrow to some operations by giving them a private copy, or rename the shared component
 * where the scope covers all of them — and neither has an analogue here, because a parameter belongs to
 * one operation already. A scope decides which operations are visited and nothing else.
 *
 * @internal
 */
interface OperationVerb
{
    /**
     * What this verb names, as a diagnostic spells it — `the query parameter "search"`.
     */
    public function declares(): string;

    /**
     * The edit, on one operation. `$outcome` accumulates the strongest thing seen across every
     * operation in scope, so an implementation only ever raises it.
     *
     * `$scope` is what the operation's nodes belong to: its own identity where it has one, and where it
     * stands where it does not — the same fallback a forked schema's ids are re-minted against. A verb
     * that moves a name an identity is derived FROM has to re-mint that identity, and this is what it
     * mints against.
     *
     * @param  array<array-key, mixed>  $operation
     * @return array<array-key, mixed>
     */
    public function apply(array $operation, string $scope, IdentityGenerator $identity, VerbOutcome &$outcome): array;

    /**
     * What an outcome has to say for itself. An applied verb says nothing.
     */
    public function diagnose(VerbOutcome $outcome, VersionChange $change): ?Diagnostic;
}
