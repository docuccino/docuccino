<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Testing;

use Docuccino\Core\Extensions\Context\DocumentConfig;
use Docuccino\Core\Extensions\Context\ExportTarget;
use Docuccino\Laravel\Support\Paths;

/**
 * Which file the contract assertions read.
 *
 * There is deliberately no option of its own: an application that exports a document has already said
 * where it lands, so the assertions read `export.targets` — the UIR target when there is one, because
 * only UIR carries the provenance that makes a failure actionable, else whatever the document does
 * write. A second place to name the file would be a second place to get it wrong.
 */
final class ArtifactLocator
{
    /** The artifact to assert against, as an absolute path. */
    public static function locate(DocumentConfig $config, string $basePath, ?string $override = null): string
    {
        if ($override !== null) {
            return Paths::absolute($override, $basePath);
        }

        return Paths::absolute(self::preferred($config)->path, $basePath);
    }

    /**
     * The document's UIR target, else its first target. UIR first because provenance only survives
     * there — an OpenAPI artifact still validates, it just cannot say who wrote the schema.
     */
    public static function preferred(DocumentConfig $config): ExportTarget
    {
        $targets = $config->exportTargets();

        foreach ($targets as $target) {
            if ($target->format === 'uir') {
                return $target;
            }
        }

        return $targets[0];
    }
}
