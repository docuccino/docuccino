<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Commands;

use Docuccino\Core\Diff\DocumentDiffer;
use Docuccino\Core\Diff\IncomparableDocumentsException;
use Docuccino\Core\Document\UirDocument;
use Docuccino\Core\Inference\TypeEngine;
use Docuccino\Core\Pipeline\GenerationResult;
use Docuccino\Laravel\Pipeline\DocumentBuilder;
use Docuccino\Laravel\Support\Paths;
use Docuccino\Laravel\Support\Psr4Namespaces;
use Docuccino\Laravel\Support\TerminalText;
use Docuccino\Laravel\Versioning\ChangeDirectories;
use Docuccino\Laravel\Versioning\Scaffold\ChangeScaffolder;
use Docuccino\Laravel\Versioning\Scaffold\ChangeStub;
use Docuccino\Laravel\Versioning\Scaffold\ScaffoldedChange;
use Illuminate\Console\Command;

/**
 * Drafts the `#[ApiVersionChange]` classes for the version being cut, out of the diff between the
 * document the previous version published and the one this code builds.
 *
 * Nothing here is new machinery: the git read and the artifact reader are `docuccino:diff`'s
 * ({@see ReadsCommittedArtifact}), the comparison is {@see DocumentDiffer} over the same stable
 * identities, and the vocabulary is the one the build already reads. What it adds is the part a diff
 * cannot do on its own — turning a difference into a declaration, with the difference's own factual
 * sentence as the starting `description`. The author supplies the WHY, which is the one thing nothing
 * else knows.
 *
 * Its own command rather than a step of `docuccino:install`: `install` runs once and idempotently,
 * while scaffolding a version is recurring work done when one is cut.
 *
 * Where it writes, when several directories are configured, is the FIRST of them unless `--in` says
 * otherwise — and it always reports which. Inferring the module from the changed class's own location
 * would be a guess about somebody's layout, and a guess that puts a file in the wrong module is worse
 * than a flag.
 */
final class VersionChangesCommand extends Command
{
    use GuardsEnabled;
    use PrintsSections;
    use ReadsCommittedArtifact;
    use StringOptions;

    protected $signature = 'docuccino:version-changes
        {old : Path to the committed UIR artifact of the version this one diverges from}
        {document? : The configured document key to build as the new side (defaults to "default")}
        {--against= : Read `old` from this git ref (git show <ref>:<old>) instead of the working tree}
        {--since= : The version the scaffolded changes shipped in (defaults to the document\'s info.version)}
        {--in= : Which configured api_version.changes directory to write into (defaults to the first)}
        {--dry-run : Report what would be written, and write nothing}
        {--memory-limit= : Raise the PHP memory limit for inference (e.g. 2G)}';

    protected $description = 'Scaffold the version-change classes for the differences between a published version and the current build.';

    public function __construct(
        private readonly DocumentDiffer $differ = new DocumentDiffer,
        private readonly ChangeScaffolder $scaffolder = new ChangeScaffolder,
    ) {
        parent::__construct();
    }

    public function handle(DocumentBuilder $builder, TypeEngine $engine): int
    {
        if ($this->abortIfDisabled()) {
            return self::FAILURE;
        }

        $key = $this->documentKey($builder);
        if ($key === null) {
            return self::FAILURE;
        }

        $path = $this->argument('old');
        $old = $this->committedArtifact(is_string($path) ? $path : '', $this->stringOption('against'));
        if ($old === null) {
            return self::FAILURE;
        }

        $config = $builder->config($key);

        $since = $this->stringOption('since') ?? $config->apiVersion();
        if ($since === null) {
            $this->error(sprintf(
                'The "%s" document states no version to scaffold against. Set its info.version, or pass --since.',
                TerminalText::of($key),
            ));

            return self::FAILURE;
        }

        [$directories] = ChangeDirectories::resolve(base_path(), $config);
        $directory = $this->target($directories, $key);
        if ($directory === null) {
            return self::FAILURE;
        }

        $namespace = Psr4Namespaces::for(base_path(), $directory);
        if ($namespace === null) {
            $this->error(sprintf(
                'No PSR-4 prefix in composer.json covers %s, so a class written there would never be autoloaded — and a change nothing loads is a change nothing applies. Map the directory and run this again.',
                TerminalText::of($this->readable($directory)),
            ));

            return self::FAILURE;
        }

        $new = $builder->build($key, $engine);

        return $this->scaffold($old, $new, $since, $directory, $namespace, $key);
    }

    /**
     * Diff, plan, write, report. One method because the report is the product here as much as the files
     * are: a difference the vocabulary cannot express is only useful to the author if it is printed
     * beside the ones that were.
     */
    private function scaffold(UirDocument $old, GenerationResult $new, string $since, string $directory, string $namespace, string $key): int
    {
        try {
            $changeset = $this->differ->diff($old, $new->document);
        } catch (IncomparableDocumentsException $exception) {
            $this->error(TerminalText::markupOnly($exception->getMessage()));

            return self::FAILURE;
        }

        $plan = $this->scaffolder->plan($changeset, $old, $new->document, $new->schemaSources, $since);
        $stub = new ChangeStub(base_path());

        $this->section('Scaffold', sprintf(
            '"%s" at %s, into %s, from the %s stub.',
            $key,
            $since,
            $this->readable($directory),
            $stub->published() ? 'published' : 'packaged',
        ));

        if ($plan->changes === []) {
            $this->line('Nothing the version-change vocabulary expresses.');
        }

        $written = [];
        $skipped = [];

        foreach ($plan->changes as $change) {
            $file = $change->file($directory);

            // Never overwritten, and never merged into either: the file is the author's the moment it
            // exists, and the sentence they wrote in it is the whole value of the thing.
            if (is_file($file)) {
                $skipped[] = $change;

                continue;
            }

            if (! $this->option('dry-run') && ! $this->write($file, $stub->render($change, $namespace))) {
                return self::FAILURE;
            }

            $written[] = $change;
        }

        $this->report($written, $skipped, $plan->gaps);

        return self::SUCCESS;
    }

    /**
     * @param  list<ScaffoldedChange>  $written
     * @param  list<ScaffoldedChange>  $skipped
     * @param  list<string>  $gaps
     */
    private function report(array $written, array $skipped, array $gaps): void
    {
        if ($written !== []) {
            $this->section($this->option('dry-run') ? 'Would write' : 'Written');

            foreach ($written as $change) {
                $this->line(sprintf('  %s — %s', TerminalText::of($change->class), TerminalText::of($change->description)));

                if ($change->note !== null) {
                    $this->line(sprintf('    <fg=yellow>%s</>', TerminalText::of($change->note)));
                }
            }

            $this->newLine();
            $this->line('Each description says WHAT changed. Add why it changed, and whom it affects — that half is');
            $this->line('the reason the declaration exists, and it is the half nothing here can write for you.');
        }

        if ($skipped !== []) {
            $this->section('Left alone', 'A class of that name is already there, and it is yours.');

            foreach ($skipped as $change) {
                $this->line(sprintf('  %s', TerminalText::of($change->class)));
            }
        }

        if ($gaps !== []) {
            $this->section('Not declared', 'Real differences the vocabulary does not express. Nothing was written for them.');

            foreach ($gaps as $gap) {
                $this->line(sprintf('  %s', TerminalText::of($gap)));
            }
        }
    }

    /** False when the file could not be written, which is reported here and fails the run. */
    private function write(string $file, string|false $contents): bool
    {
        if ($contents === false) {
            $this->error('The version-change stub could not be read, so nothing was written.');

            return false;
        }

        if (! is_dir(dirname($file)) && ! @mkdir(dirname($file), 0755, true) && ! is_dir(dirname($file))) {
            $this->error(sprintf('Could not create %s.', TerminalText::of($this->readable(dirname($file)))));

            return false;
        }

        if (@file_put_contents($file, $contents) === false) {
            $this->error(sprintf('Could not write %s.', TerminalText::of($this->readable($file))));

            return false;
        }

        return true;
    }

    /**
     * The directory to write into: the one `--in` names, else the first configured. Reported by the
     * caller either way, because "the first" is only obvious to whoever wrote the config.
     *
     * @param  list<string>  $directories
     */
    private function target(array $directories, string $key): ?string
    {
        if ($directories === []) {
            $this->error(sprintf(
                'The "%s" document configures no api_version.changes directory, so there is nowhere to write a change class.',
                TerminalText::of($key),
            ));

            return null;
        }

        $wanted = $this->stringOption('in');
        if ($wanted === null) {
            return $directories[0];
        }

        foreach ($directories as $directory) {
            if ($directory === $wanted || $this->readable($directory) === trim($wanted, '/')) {
                return $directory;
            }
        }

        $this->error(sprintf(
            '--in=%s names none of this document\'s change directories: %s.',
            TerminalText::of($wanted),
            TerminalText::of(implode(', ', array_map($this->readable(...), $directories))),
        ));

        return null;
    }

    /** A path as the author wrote it: relative to the application, since that is what config holds. */
    private function readable(string $path): string
    {
        return Paths::relative($path, base_path()) ?? $path;
    }

    private function documentKey(DocumentBuilder $builder): ?string
    {
        $document = $this->argument('document');
        $key = is_string($document) && $document !== '' ? $document : 'default';

        if (! $builder->hasDocument($key)) {
            $this->error(sprintf('Unknown document "%s".', TerminalText::of($key)));

            return null;
        }

        return $key;
    }
}
