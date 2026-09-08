<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Commands;

use Docuccino\Core\Diagnostics\Diagnostic;
use Docuccino\Laravel\Config\BuildConfig;
use Docuccino\Laravel\Config\ConfigSplit;
use Illuminate\Console\Command;

/**
 * Stops a command that would build a document from configuration the build does not read: no
 * `docuccino.yaml`, and the build settings still sitting in `config/docuccino.php`.
 *
 * Before the build and not through `--fail-on`, on {@see ExportDiagnostics}' precedent. `--fail-on`
 * is a gate over what a build FOUND, so the quietest thing a project can ask for is `none` — and an
 * error printed from inside the build would then exit 0 after a full analysis, having written an
 * artifact assembled from defaults rather than from what the author wrote.
 *
 * `docuccino:install` is exempt because it is the remedy, and `docuccino:clear` because it reads no
 * configuration — it empties caches, which is the one thing still worth doing here.
 *
 * @mixin Command
 */
trait RefusesUnreadConfig
{
    /**
     * The renderer every gating command already uses, so the refusal prints as the diagnostic it is —
     * code, severity, help and reference link — rather than as a second phrasing of one report.
     *
     * @param  list<Diagnostic>  $diagnostics
     */
    abstract protected function renderDiagnostics(string $document, array $diagnostics): void;

    /** True — having said why — when the configuration this build would read is not the configured one. */
    protected function abortIfConfigUnread(): bool
    {
        $refusal = ConfigSplit::notMigrated(app(BuildConfig::class));

        if ($refusal === null) {
            return false;
        }

        // Reported against the file holding the settings, which is where the reader has to go: the
        // other file does not exist yet, and naming a document key would suggest the document is the
        // thing that is wrong.
        $this->renderDiagnostics('config/docuccino.php', [$refusal]);

        return true;
    }
}
