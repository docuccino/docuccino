<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Commands;

use Docuccino\Core\Diagnostics\Severity;
use Docuccino\Core\Document\UirDocument;
use Docuccino\Core\Emit\EmitOptions;
use Docuccino\Core\Emit\OpenApi31DownlevelEmitter;
use Docuccino\Core\Emit\OpenApi32Emitter;
use Docuccino\Core\Emit\ProvenanceLevel;
use Docuccino\Core\Emit\UirEmitter;
use Docuccino\Core\Extensions\Context\DocumentConfig;
use Docuccino\Core\Inference\TypeEngine;
use Docuccino\Laravel\Pipeline\DocumentBuilder;
use Docuccino\Laravel\Pipeline\GenerationResult;
use Docuccino\Laravel\Support\Paths;
use Illuminate\Console\Command;

/**
 * Runs the pipeline for a document (or every document) and writes its UIR / OpenAPI artifact
 * (design §Commands). Diagnostics are printed grouped by route deterministically; the exit code
 * honours `--fail-on`.
 */
final class ExportCommand extends Command
{
    use GuardsEnabled;
    use RendersDiagnostics;

    /** The emitter formats a caller may name via --format. */
    private const FORMATS = ['uir', 'openapi-3.2', 'openapi-3.1'];

    protected $signature = 'docuccino:export
        {document? : The configured document key (defaults to every document)}
        {--format= : uir | openapi-3.2 | openapi-3.1 (defaults to openapi-3.2)}
        {--out= : Output path (defaults to the document export path)}
        {--fail-on=none : none | warning | error — the severity that makes the command exit non-zero}
        {--provenance=winners : none | winners | full — UIR provenance detail}
        {--yaml : Emit YAML instead of JSON}';

    protected $description = 'Generate and export API documentation from your routes.';

    public function handle(DocumentBuilder $builder, TypeEngine $engine): int
    {
        if ($this->abortIfDisabled()) {
            return self::FAILURE;
        }

        $only = $this->argument('document');
        if (is_string($only) && ! $builder->hasDocument($only)) {
            $this->error(sprintf('Unknown document "%s".', $only));

            return self::FAILURE;
        }

        // An explicit --format must name a real emitter — a typo errors rather than silently
        // falling back to OpenAPI 3.2 (which would ship the wrong artifact).
        $format = $this->option('format');
        if (is_string($format) && $format !== '' && ! in_array($format, self::FORMATS, true)) {
            $this->error(sprintf('Unknown --format "%s"; expected one of: %s.', $format, implode(', ', self::FORMATS)));

            return self::FAILURE;
        }

        // A single --out path cannot receive more than one document — the later ones would clobber
        // the earlier (arch F9). Require a specific document, or drop --out and use per-document
        // export.path.
        $out = $this->option('out');
        if (is_string($out) && $out !== '' && ! is_string($only) && count($builder->documentKeys()) > 1) {
            $this->error('--out cannot be used when exporting multiple documents; pass a document argument or configure per-document export.path.');

            return self::FAILURE;
        }

        $exit = self::SUCCESS;

        foreach ($builder->documentKeys() as $key) {
            if (is_string($only) && $key !== $only) {
                continue;
            }

            $result = $builder->build($key, $engine);

            $this->write($builder->config($key), $result->document);
            $this->renderDiagnostics($key, $result->diagnostics);

            if ($this->shouldFail($result)) {
                $exit = self::FAILURE;
            }
        }

        return $exit;
    }

    private function write(DocumentConfig $config, UirDocument $document): void
    {
        $format = is_string($this->option('format')) && $this->option('format') !== ''
            ? $this->option('format')
            : 'openapi-3.2';
        $yaml = (bool) $this->option('yaml');

        $options = (new EmitOptions)->withYaml($yaml)->withProvenance($this->provenanceLevel());

        $output = match ($format) {
            'uir' => (new UirEmitter)->emit($document, $options),
            'openapi-3.1' => (new OpenApi31DownlevelEmitter)->emit($document, $options),
            default => (new OpenApi32Emitter)->emit($document, $options),
        };

        $path = $this->outputPath($config);
        $directory = dirname($path);
        if (! is_dir($directory)) {
            @mkdir($directory, 0755, true);
        }

        file_put_contents($path, $output);
        $this->info(sprintf('Wrote %s (%s).', $path, $format));
    }

    private function outputPath(DocumentConfig $config): string
    {
        $out = $this->option('out');
        $path = is_string($out) && $out !== '' ? $out : $config->exportPath();

        return Paths::absolute($path, base_path());
    }

    private function provenanceLevel(): ProvenanceLevel
    {
        return ProvenanceLevel::tryFrom(is_string($this->option('provenance')) ? $this->option('provenance') : '')
            ?? ProvenanceLevel::Winners;
    }

    private function shouldFail(GenerationResult $result): bool
    {
        return match (is_string($this->option('fail-on')) ? $this->option('fail-on') : 'none') {
            'warning' => $result->has(Severity::Error) || $result->has(Severity::Warning),
            'error' => $result->has(Severity::Error),
            default => false,
        };
    }
}
