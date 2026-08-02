<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Console;

use Docuccino\Core\Diagnostics\Diagnostic;
use Docuccino\Core\Diagnostics\Severity;
use Docuccino\Core\Document\UirDocument;
use Docuccino\Core\Emit\EmitOptions;
use Docuccino\Core\Emit\OpenApi31DownlevelEmitter;
use Docuccino\Core\Emit\OpenApi32Emitter;
use Docuccino\Core\Emit\ProvenanceLevel;
use Docuccino\Core\Emit\UirEmitter;
use Docuccino\Core\Extensions\Context\DocumentConfig;
use Docuccino\Core\Inference\TypeEngine;
use Docuccino\Core\Overlay\InvalidOverlayException;
use Docuccino\Core\Overlay\OverlayDocument;
use Docuccino\Laravel\Config\DocumentConfigFactory;
use Docuccino\Laravel\Pipeline\DocumentGenerator;
use Docuccino\Laravel\Pipeline\GenerationResult;
use Illuminate\Console\Command;
use Symfony\Component\Yaml\Yaml;

/**
 * Runs the pipeline for a document and writes its UIR / OpenAPI artifact (design §Commands).
 * Diagnostics are printed grouped by route deterministically; the exit code honours `--fail-on`.
 */
final class ExportCommand extends Command
{
    protected $signature = 'docuccino:export
        {document? : The configured document key (defaults to every document)}
        {--format= : uir | openapi-3.2 | openapi-3.1 (defaults to openapi-3.2)}
        {--out= : Output path (defaults to the document export path)}
        {--fail-on=none : none | warning | error — the severity that makes the command exit non-zero}
        {--provenance=winners : none | winners | full — UIR provenance detail}
        {--yaml : Emit YAML instead of JSON}';

    protected $description = 'Generate and export API documentation from your routes.';

    public function handle(
        DocumentConfigFactory $configs,
        TypeEngine $engine,
        DocumentGenerator $generator,
    ): int {
        /** @var array<string, mixed> $documents */
        $documents = (array) config('docuccino.documents', []);
        $configuredError = config('docuccino.on_route_error');
        $onRouteError = is_string($configuredError) ? $configuredError : 'skeleton';

        $only = $this->argument('document');
        if (is_string($only) && ! isset($documents[$only])) {
            $this->error(sprintf('Unknown document "%s".', $only));

            return self::FAILURE;
        }

        /** @var list<class-string|object> $configExtensions */
        $configExtensions = array_values(array_filter(
            (array) config('docuccino.extensions', []),
            static fn (mixed $extension): bool => is_string($extension) || is_object($extension),
        ));

        $exit = self::SUCCESS;

        foreach ($documents as $key => $raw) {
            if (is_string($only) && $key !== $only) {
                continue;
            }
            if (! is_array($raw)) {
                continue;
            }

            $config = $configs->make((string) $key, $this->stringKeyed($raw), $onRouteError);
            $result = $generator->generate($config, $engine, $configExtensions, $this->overlays($config));

            $this->write($config, $result->document);
            $this->renderDiagnostics((string) $key, $result->diagnostics);

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
            @mkdir($directory, 0777, true);
        }

        file_put_contents($path, $output);
        $this->info(sprintf('Wrote %s (%s).', $path, $format));
    }

    private function outputPath(DocumentConfig $config): string
    {
        $out = $this->option('out');
        if (is_string($out) && $out !== '') {
            return $this->absolute($out);
        }

        $export = is_array($config->raw['export'] ?? null) ? $config->raw['export'] : [];
        $path = is_string($export['path'] ?? null) ? $export['path'] : 'docs/openapi.json';

        return $this->absolute($path);
    }

    private function absolute(string $path): string
    {
        return str_starts_with($path, '/') ? $path : base_path($path);
    }

    private function provenanceLevel(): ProvenanceLevel
    {
        return ProvenanceLevel::tryFrom(is_string($this->option('provenance')) ? $this->option('provenance') : '')
            ?? ProvenanceLevel::Winners;
    }

    /**
     * @return list<OverlayDocument>
     */
    private function overlays(DocumentConfig $config): array
    {
        $overlays = [];
        foreach ($config->overlays as $pattern) {
            foreach (glob($this->absolute($pattern)) ?: [] as $file) {
                try {
                    /** @var array<string, mixed> $parsed */
                    $parsed = (array) Yaml::parseFile($file);
                    $overlays[] = OverlayDocument::fromArray($parsed);
                } catch (InvalidOverlayException $exception) {
                    $this->warn(sprintf('Skipped overlay %s: %s', $file, $exception->getMessage()));
                }
            }
        }

        return $overlays;
    }

    /**
     * @param  list<Diagnostic>  $diagnostics
     */
    private function renderDiagnostics(string $document, array $diagnostics): void
    {
        if ($diagnostics === []) {
            return;
        }

        $this->newLine();
        $this->line(sprintf('<comment>Diagnostics for %s:</comment>', $document));

        $current = "\0";
        foreach ($diagnostics as $diagnostic) {
            $group = $diagnostic->routeSignature ?? '(document)';
            if ($group !== $current) {
                $current = $group;
                $this->line('  '.$group);
            }
            $this->line(sprintf('    [%s] %s: %s', $diagnostic->severity->value, $diagnostic->code, $diagnostic->message));
        }
    }

    /**
     * @param  array<array-key, mixed>  $value
     * @return array<string, mixed>
     */
    private function stringKeyed(array $value): array
    {
        $out = [];
        foreach ($value as $key => $item) {
            $out[(string) $key] = $item;
        }

        return $out;
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
