<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Versioning;

use Docuccino\Core\Diagnostics\Diagnostic;
use Docuccino\Core\Diagnostics\Severity;
use Docuccino\Core\Extensions\Context\DocumentConfig;
use Docuccino\Core\Support\ConfinedPath;
use Docuccino\Core\Support\PlainText;
use Docuccino\Laravel\Support\Paths;

/**
 * The one reading of `api_version.changes`: configured glob patterns in, absolute directories out,
 * with what could not be resolved said once.
 *
 * One reading because there are three readers — the collector that discovers the change classes,
 * `docuccino:watch`, which watches the same trees, and the scaffold command, which writes into the
 * first of them. Two of them resolving the same patterns separately is how a build reads a module
 * directory that a watch session never notices.
 *
 * A pattern may be a glob, so a modular application matches its modules in one entry rather than
 * re-listing them every time one is added. Glob results are sorted and the entries keep their
 * configured order, so the answer is a function of the configuration rather than of the filesystem's
 * enumeration — and every resolved directory goes back through {@see ConfinedPath} rather than being
 * trusted because its pattern was: a wildcard matching a symlink out of the application is the one way
 * a glob becomes an escape.
 *
 * A directory it names may not exist. A literal entry is still returned when it is missing — watching
 * it is what registers the change class somebody writes next — so the reader that opens one checks,
 * and says nothing when it is absent because {@see resolve()} already has.
 *
 * @internal
 */
final class ChangeDirectories
{
    /** The characters that make a configured entry a pattern rather than a path. */
    private const string GLOB_CHARACTERS = '*?[';

    /**
     * The directories `$document` declares its changes live in, and the diagnostics for the entries
     * that named none.
     *
     * @return array{0: list<string>, 1: list<Diagnostic>} absolute paths, deduped, configured order
     */
    public static function resolve(string $basePath, DocumentConfig $document): array
    {
        $directories = [];
        $diagnostics = [];

        foreach ($document->apiVersionChangeDirs() as $configured) {
            $resolved = ConfinedPath::configuredDir($basePath, $configured);

            if ($resolved === null) {
                $diagnostics[] = new Diagnostic(
                    severity: Severity::Warning,
                    code: 'versioning.dir-escapes-base',
                    message: sprintf('The version-changes directory "%s" does not name a path inside the application and was ignored.', PlainText::of($configured)),
                );

                continue;
            }

            [$matched, $escaped] = self::expand($basePath, $configured, $resolved);

            if ($escaped) {
                $diagnostics[] = new Diagnostic(
                    severity: Severity::Warning,
                    code: 'versioning.dir-escapes-base',
                    message: sprintf('The version-changes directory "%s" matched a path outside the application, which was ignored.', PlainText::of($configured)),
                );
            }

            if (array_filter($matched, is_dir(...)) === []) {
                $diagnostics[] = new Diagnostic(
                    severity: Severity::Warning,
                    code: 'versioning.dir-missing',
                    message: sprintf('The configured version-changes directory "%s" does not exist.', PlainText::of($configured)),
                    help: 'Create it or drop the entry from documents.*.api_version.changes.',
                );
            }

            foreach ($matched as $directory) {
                $directories[$directory] = true;
            }
        }

        return [array_keys($directories), $diagnostics];
    }

    /**
     * What one entry resolves to, and whether anything it matched was refused.
     *
     * A literal entry is itself, present or not — the caller wants to watch a directory the author is
     * about to create. A pattern is every directory it matches, sorted, each re-confined: an absolute
     * pattern is a deliberate statement about this machine and stands as written, while a relative one
     * may not leave the application however its `*` was spelled.
     *
     * @return array{0: list<string>, 1: bool}
     */
    private static function expand(string $basePath, string $configured, string $resolved): array
    {
        if (strpbrk($configured, self::GLOB_CHARACTERS) === false) {
            return [[$resolved], false];
        }

        $matches = glob($resolved, GLOB_ONLYDIR) ?: [];
        sort($matches, SORT_STRING);

        $absolute = str_starts_with($configured, '/');

        $kept = [];
        $escaped = false;

        foreach ($matches as $match) {
            $confined = $absolute ? $match : self::confine($basePath, $match);

            if ($confined === null) {
                $escaped = true;

                continue;
            }

            $kept[] = $confined;
        }

        return [$kept, $escaped];
    }

    /** A globbed match put back through confinement, by the relative name it holds under the base. */
    private static function confine(string $basePath, string $match): ?string
    {
        $relative = Paths::relative($match, $basePath);

        return $relative === null ? null : ConfinedPath::configuredDir($basePath, $relative);
    }
}
