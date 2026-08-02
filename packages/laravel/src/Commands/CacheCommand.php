<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Commands;

use Docuccino\Core\Emit\OpenApi32Emitter;
use Docuccino\Core\Inference\TypeEngine;
use Docuccino\Laravel\Pipeline\DocumentBuilder;
use Docuccino\Laravel\Runtime\DocumentCache;
use Illuminate\Console\Command;

/**
 * Builds a document (or every document) and stores its served OpenAPI payload in the configured
 * Laravel cache store, so the runtime endpoint can answer `viewer.source: cache` without a rebuild.
 */
final class CacheCommand extends Command
{
    use GuardsEnabled;
    use RendersDiagnostics;

    protected $signature = 'docuccino:cache {document? : The configured document key (defaults to every document)}';

    protected $description = 'Build and cache the API document(s) for the runtime endpoint.';

    public function handle(DocumentBuilder $builder, TypeEngine $engine, DocumentCache $cache): int
    {
        if ($this->abortIfDisabled()) {
            return self::FAILURE;
        }

        $only = $this->argument('document');
        if (is_string($only) && ! $builder->hasDocument($only)) {
            $this->error(sprintf('Unknown document "%s".', $only));

            return self::FAILURE;
        }

        foreach ($builder->documentKeys() as $key) {
            if (is_string($only) && $key !== $only) {
                continue;
            }

            $result = $builder->build($key, $engine);
            $cache->put($key, (new OpenApi32Emitter)->emit($result->document));

            $this->info(sprintf('Cached document "%s".', $key));
            $this->renderDiagnostics($key, $result->diagnostics);
        }

        return self::SUCCESS;
    }
}
