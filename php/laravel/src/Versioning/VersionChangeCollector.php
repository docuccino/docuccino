<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Versioning;

use Docuccino\Attributes\Versioning\ApiVersionChange;
use Docuccino\Attributes\Versioning\RenamedResponseField;
use Docuccino\Core\Diagnostics\Diagnostic;
use Docuccino\Core\Diagnostics\Severity;
use Docuccino\Core\Extensions\Context\AttributeSet;
use Docuccino\Core\Extensions\Context\DocumentConfig;
use Docuccino\Core\Support\ConfinedPath;
use Docuccino\Core\Support\PlainText;
use Docuccino\Laravel\Routing\AttributeCollector;
use Docuccino\Laravel\Support\DeclaredClasses;
use ReflectionClass;

/**
 * Reads `documents.*.api_version.changes.dir` into the version changes that document derives an older
 * shape from. Reflection only: an attribute argument is a constant expression the compiler already
 * settled, so nothing here parses, folds or executes a line of the application.
 *
 * The answer is ordered `since` descending then FQCN ascending — the order the changes are APPLIED in,
 * newest first, so each hands the shape of the version below it to the next. Nothing depends on the
 * filesystem's enumeration order.
 *
 * @internal
 */
final readonly class VersionChangeCollector
{
    public function __construct(
        private string $basePath,
        private AttributeCollector $attributes = new AttributeCollector,
    ) {}

    /**
     * @return array{0: list<VersionChange>, 1: list<Diagnostic>}
     */
    public function collect(DocumentConfig $document): array
    {
        $configured = $document->apiVersionChangesDir();
        if ($configured === null) {
            return [[], []];
        }

        $dir = ConfinedPath::configuredDir($this->basePath, $configured);
        if ($dir === null) {
            return [[], [new Diagnostic(
                severity: Severity::Warning,
                code: 'versioning.dir-escapes-base',
                message: sprintf('The version-changes directory "%s" does not name a path inside the application and was ignored.', PlainText::of($configured)),
            )]];
        }

        if (! is_dir($dir)) {
            return [[], [new Diagnostic(
                severity: Severity::Warning,
                code: 'versioning.dir-missing',
                message: sprintf('The configured version-changes directory "%s" does not exist.', $configured),
                help: 'Create it or unset documents.*.api_version.changes.dir.',
            )]];
        }

        $diagnostics = [];
        $changes = [];

        foreach (DeclaredClasses::in($dir) as $class) {
            $change = $this->declare($class, $diagnostics);
            if ($change !== null) {
                $changes[] = $change;
            }
        }

        usort($changes, static fn (VersionChange $a, VersionChange $b): int => strcmp($b->since, $a->since) ?: strcmp($a->class, $b->class));

        return [$changes, $diagnostics];
    }

    /**
     * @param  class-string  $class
     * @param  list<Diagnostic>  $diagnostics
     */
    private function declare(string $class, array &$diagnostics): ?VersionChange
    {
        $reflection = new ReflectionClass($class);

        $attributes = $this->attributesOf($reflection, $diagnostics);
        $declaration = $attributes->first(ApiVersionChange::class);
        if ($declaration === null) {
            // A helper sitting beside the changes is not a change. Nothing to report: the directory is
            // the application's, and only an #[ApiVersionChange] claims to be read from it.
            return null;
        }

        $since = trim($declaration->since);
        if ($since === '') {
            $diagnostics[] = self::unapplicable($class, 'its #[ApiVersionChange] names no version, so nothing can tell which versions it applies to');

            return null;
        }

        $renames = [];
        foreach ($attributes->all(RenamedResponseField::class) as $rename) {
            if (trim($rename->from) === '' || trim($rename->to) === '') {
                $diagnostics[] = self::unapplicable($class, 'one of its #[RenamedResponseField] declarations leaves `from:` or `to:` empty');

                continue;
            }

            if ($rename->from === $rename->to) {
                $diagnostics[] = self::unapplicable($class, sprintf('one of its #[RenamedResponseField] declarations renames "%s" to itself', PlainText::of($rename->from)));

                continue;
            }

            $renames[] = $rename;
        }

        return new VersionChange(
            class: $class,
            since: $since,
            description: trim($declaration->description),
            renames: $renames,
        );
    }

    /** The one mint for "this declaration cannot be applied as it is written". */
    public static function unapplicable(string $class, string $problem): Diagnostic
    {
        return new Diagnostic(
            severity: Severity::Warning,
            code: 'versioning.change-invalid',
            message: sprintf('%s was skipped: %s.', $class, $problem),
            help: 'A change declares what the API did BEFORE its version: `to:` is the field name in the code today, `from:` the one older versions publish.',
        );
    }

    /**
     * @param  ReflectionClass<object>  $class
     * @param  list<Diagnostic>  $diagnostics
     */
    private function attributesOf(ReflectionClass $class, array &$diagnostics): AttributeSet
    {
        return $this->attributes->collectOne(
            $class,
            $class->getName(),
            static function (Diagnostic $diagnostic) use (&$diagnostics): void {
                $diagnostics[] = $diagnostic;
            },
        );
    }
}
