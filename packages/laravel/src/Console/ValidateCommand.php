<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Console;

use Docuccino\Core\Diagnostics\Diagnostic;
use Docuccino\Core\Diagnostics\Severity;
use Docuccino\Core\Inference\TypeEngine;
use Docuccino\Laravel\Pipeline\DocumentBuilder;
use Illuminate\Console\Command;

/**
 * Runs the pipeline for a document (or every document) and validates the assembled UIR against
 * the bundled UIR schema. Schema failures surface as `document.schema-invalid` error diagnostics
 * (the generator already validates internally); this command renders them grouped by route and
 * exits non-zero per `--fail-on`, so CI can gate on a structurally-valid document.
 */
final class ValidateCommand extends Command
{
    use RendersDiagnostics;

    protected $signature = 'docuccino:validate
        {document? : The configured document key (defaults to every document)}
        {--fail-on=none : none | warning | error — extra diagnostic severity that also fails (a schema violation always fails)}';

    protected $description = 'Validate the generated document(s) against the bundled UIR schema.';

    public function handle(DocumentBuilder $builder, TypeEngine $engine): int
    {
        $only = $this->argument('document');
        if (is_string($only) && ! $builder->hasDocument($only)) {
            $this->error(sprintf('Unknown document "%s".', $only));

            return self::FAILURE;
        }

        $exit = self::SUCCESS;

        foreach ($builder->documentKeys() as $key) {
            if (is_string($only) && $key !== $only) {
                continue;
            }

            $result = $builder->build($key, $engine);
            $schemaErrors = $this->schemaErrors($result->diagnostics);

            if ($schemaErrors === []) {
                $this->info(sprintf('%s: valid against UIR %s.', $key, $this->uirVersion($result->document->toArray())));
            } else {
                $this->error(sprintf('%s: %d schema violation(s).', $key, count($schemaErrors)));
            }

            $this->renderDiagnostics($key, $result->diagnostics);

            if ($schemaErrors !== [] || $this->shouldFail($result->diagnostics)) {
                $exit = self::FAILURE;
            }
        }

        return $exit;
    }

    /**
     * @param  list<Diagnostic>  $diagnostics
     * @return list<Diagnostic>
     */
    private function schemaErrors(array $diagnostics): array
    {
        return array_values(array_filter(
            $diagnostics,
            static fn (Diagnostic $d): bool => $d->code === 'document.schema-invalid',
        ));
    }

    /**
     * @param  array<string, mixed>  $document
     */
    private function uirVersion(array $document): string
    {
        return is_string($document['uir'] ?? null) ? $document['uir'] : '1.0.0';
    }

    /**
     * @param  list<Diagnostic>  $diagnostics
     */
    private function shouldFail(array $diagnostics): bool
    {
        $has = static function (Severity $severity) use ($diagnostics): bool {
            foreach ($diagnostics as $diagnostic) {
                if ($diagnostic->severity === $severity) {
                    return true;
                }
            }

            return false;
        };

        return match (is_string($this->option('fail-on')) ? $this->option('fail-on') : 'none') {
            'warning' => $has(Severity::Error) || $has(Severity::Warning),
            'error' => $has(Severity::Error),
            default => false,
        };
    }
}
