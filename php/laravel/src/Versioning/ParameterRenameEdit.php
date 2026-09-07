<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Versioning;

use Docuccino\Core\Diagnostics\Diagnostic;
use Docuccino\Core\Diagnostics\Severity;
use Docuccino\Core\Document\ChangedFieldExamples;
use Docuccino\Core\Identity\IdentityGenerator;
use Docuccino\Core\Support\PlainText;
use Docuccino\Laravel\Support\ParameterLocations;

/**
 * `#[RenamedParameter]` as the transformer applies it: the parameter goes back to the name older
 * versions accept, and its identity is re-minted to match.
 *
 * **The re-mint is the load-bearing half.** A parameter's `x-docuccino.id` is a function of the
 * operation, the location AND the name, so a renamed parameter left carrying its old id publishes a
 * node whose identity claims a name it does not have — and everything downstream that resolves by id
 * (the contract index, per-node provenance, the differ's pairing) then answers about the wrong
 * parameter. Renaming without re-minting satisfies determinism and still lies.
 *
 * **No example moves.** This was worth checking rather than assuming: a parameter's `example` and its
 * `examples` map hold the parameter's own VALUE, and the entries of that map are keyed by example name.
 * The parameter's name appears nowhere inside them — not even for a `deepObject` container, whose
 * example is keyed by its MEMBERS — so unlike a schema-field rename there is no key to rewrite, and
 * {@see ChangedFieldExamples} has nothing to do here. The one position that
 * would carry a parameter name is an OAS Link Object's `parameters` map, which nothing this product
 * mints publishes; an overlay that writes one keeps what it wrote, the same way an `externalValue`
 * does.
 *
 * A parameter written as a `$ref` is left where it stands. It is shared with every site referencing it,
 * so renaming it in place would rename it for all of them — including operations a scope was written to
 * exclude — and this verb has no fork to offer, because a parameter is not something two operations
 * legitimately share in a document this product builds.
 *
 * @internal
 */
final readonly class ParameterRenameEdit implements OperationVerb
{
    /**
     * @param  string  $in  the location, already read against {@see ParameterLocations}
     * @param  string  $from  the name versions before the change accept
     * @param  string  $to  the name in the code today
     */
    public function __construct(
        private string $in,
        private string $from,
        private string $to,
    ) {}

    public function declares(): string
    {
        return sprintf('the %s parameter "%s"', $this->in, PlainText::of($this->to));
    }

    public function apply(array $operation, string $scope, IdentityGenerator $identity, VerbOutcome &$outcome): array
    {
        $parameters = $operation['parameters'] ?? null;
        if (! is_array($parameters)) {
            $outcome = $outcome->strongest(VerbOutcome::Absent);

            return $operation;
        }

        $at = null;
        $taken = false;

        foreach ($parameters as $index => $parameter) {
            $name = is_array($parameter) ? $parameter['name'] ?? null : null;
            $in = is_array($parameter) ? $parameter['in'] ?? null : null;

            if (! is_string($name) || ! is_string($in) || strtolower($in) !== $this->in) {
                continue;
            }

            if ($name === $this->to) {
                $at = $index;
            }

            if ($name === $this->from) {
                $taken = true;
            }
        }

        if ($at === null) {
            $outcome = $outcome->strongest(VerbOutcome::Absent);

            return $operation;
        }

        if ($taken) {
            $outcome = $outcome->strongest(VerbOutcome::Declined);

            return $operation;
        }

        /** @var array<array-key, mixed> $parameter */
        $parameter = $parameters[$at];

        // Assigned rather than rebuilt, so the member keeps its position and everything else it carries.
        $parameter['name'] = $this->from;

        $docuccino = $parameter['x-docuccino'] ?? null;
        if (is_array($docuccino) && is_string($docuccino['id'] ?? null)) {
            $docuccino['id'] = $identity->parameterId($scope, $this->in, $this->from);
            $parameter['x-docuccino'] = $docuccino;
        }

        $parameters[$at] = $parameter;
        $operation['parameters'] = $parameters;

        $outcome = VerbOutcome::Applied;

        return $operation;
    }

    public function diagnose(VerbOutcome $outcome, VersionChange $change): ?Diagnostic
    {
        return match ($outcome) {
            VerbOutcome::Applied => null,
            VerbOutcome::Declined => VersionChangeCollector::unapplicable($change->class, sprintf(
                'an operation already declares a %s parameter called "%s", so renaming "%s" onto it would collapse two parameters into one',
                $this->in,
                PlainText::of($this->from),
                PlainText::of($this->to),
            )),
            // The two collapse into one sentence, and that is a fact about parameters rather than a
            // shortcut. A schema verb can tell "the document publishes no such schema" from "it does,
            // and the field is gone"; a parameter has no node of its own to be published or not, so
            // there is exactly one thing to say — no operation in scope declares it.
            VerbOutcome::Absent, VerbOutcome::Unresolved => $this->missing($change),
        };
    }

    private function missing(VersionChange $change): Diagnostic
    {
        return new Diagnostic(
            severity: Severity::Warning,
            code: 'versioning.change-target-missing',
            message: sprintf(
                '%s renames %s, which %s declares, so this version still says what the code says.',
                PlainText::of($change->class),
                $this->declares(),
                $change->selectors === []
                    ? 'no operation this document publishes'
                    : 'no operation its #[AppliesTo] names',
            ),
            help: 'Update the change to name the parameter as it is spelled today, or retire it if the parameter is gone.',
        );
    }
}
