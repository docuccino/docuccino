<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Console;

use Docuccino\Laravel\Pipeline\DocumentBuilder;
use Docuccino\Laravel\Runtime\DocumentCache;
use Illuminate\Console\Command;

/**
 * Forgets the cached payload for a document (or every document), the inverse of {@see CacheCommand}.
 */
final class ClearCommand extends Command
{
    protected $signature = 'docuccino:clear {document? : The configured document key (defaults to every document)}';

    protected $description = 'Clear the cached runtime API document(s).';

    public function handle(DocumentBuilder $builder, DocumentCache $cache): int
    {
        $only = $this->argument('document');
        if (is_string($only) && ! $builder->hasDocument($only)) {
            $this->error(sprintf('Unknown document "%s".', $only));

            return self::FAILURE;
        }

        foreach ($builder->documentKeys() as $key) {
            if (is_string($only) && $key !== $only) {
                continue;
            }

            $cache->forget($key);
            $this->info(sprintf('Cleared cached document "%s".', $key));
        }

        return self::SUCCESS;
    }
}
