<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Integrations\SpatieData;

use Docuccino\Core\Diagnostics\Diagnostic;
use Docuccino\Core\Diagnostics\Severity;
use Docuccino\Core\Extensions\Context\RouteContext;
use Docuccino\Core\Extensions\Contracts\ResponseStatusResolver;
use Docuccino\Core\Inference\ActionRef;
use Docuccino\Core\Inference\DType\LiteralT;
use ReflectionClass;

/**
 * Resolves the HTTP success status a spatie Data class documents when it overrides
 * `calculateResponseStatus()` (spatie's `ResponsableData` returns 200 by default; a `Data` subclass
 * may override it to 201/202/…). The override's return TYPE is read from the engine — a single
 * constant int (`return 201;`, or a folded `Response::HTTP_CREATED`) replaces the inferred 200; a
 * conditional/computed status folds to a union or a widened scalar and is left at 200 with an info
 * diagnostic (never a guessed status). Nothing is executed.
 *
 * Only an OVERRIDE counts: the spatie trait's default method reports the vendor trait's file, so a
 * file-identity check against the Data class distinguishes a genuine override from the inherited
 * default, and a plain Data class (no override) is a no-op with no diagnostic.
 */
final class DataResponseStatus implements ResponseStatusResolver
{
    private const METHOD = 'calculateResponseStatus';

    public function resolveStatus(RouteContext $context, string $fqcn): ?int
    {
        if (! DataClassReflector::isData($fqcn) || ! class_exists($fqcn)) {
            return null;
        }

        $reflection = new ReflectionClass($fqcn);
        if (! $reflection->hasMethod(self::METHOD)) {
            return null;
        }

        $method = $reflection->getMethod(self::METHOD);
        $methodFile = $method->getFileName();

        // A trait-provided (non-overridden) method reports the trait's file, not the class's; only an
        // override declared in the Data class's own file is a documentable status.
        if ($methodFile === false || $methodFile !== $reflection->getFileName()) {
            return null;
        }

        $line = $method->getStartLine();
        $analysis = $context->engine->analyzeAction(new ActionRef($methodFile, $fqcn, self::METHOD, $line === false ? 0 : $line));
        $context->recordDependencyFiles($analysis->dependencyFiles);

        $statuses = [];
        $foldable = true;
        foreach ($analysis->returns as $return) {
            if ($return->type instanceof LiteralT && is_int($return->type->value)) {
                $statuses[] = $return->type->value;

                continue;
            }

            $foldable = false;
        }

        $statuses = array_values(array_unique($statuses));

        if ($foldable && count($statuses) === 1) {
            return $statuses[0];
        }

        $context->components->addDiagnostic(new Diagnostic(
            severity: Severity::Info,
            code: 'spatie-data.response-status-unresolved',
            message: sprintf('%s::calculateResponseStatus() does not fold to a single constant status; the success response is documented as 200.', $fqcn),
            help: 'Return one constant int (e.g. `return 201;` or a constant like Response::HTTP_CREATED) so the status can be documented; a conditional or computed status cannot be resolved statically.',
        ));

        return null;
    }
}
