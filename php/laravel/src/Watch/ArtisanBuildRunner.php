<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Watch;

/**
 * Runs one rebuild as `docuccino:export` in a FRESH PHP process.
 *
 * That is not an optimisation, it is the only way a watch loop can be right. The watching process
 * has already loaded the controllers, form requests and resources it documented, and PHP never
 * un-loads a class: an in-process rebuild would keep reflecting the code as it was when the loop
 * started, and would not see a route added since at all. `queue:listen` re-execs for the same
 * reason. What makes it cheap is that the fragment cache is on disk, so the new process picks up
 * every operation the last one built and re-analyses only what changed — which is why the run
 * carries {@see FRAGMENT_CACHE} for the build to read.
 *
 * @internal
 */
final readonly class ArtisanBuildRunner implements BuildRunner
{
    /** Turns the fragment cache on for the builds a watch session drives, whatever config defaults to. */
    public const string FRAGMENT_CACHE = 'DOCUCCINO_FRAGMENT_CACHE';

    public function __construct(
        private string $artisan,
        private string $php = PHP_BINARY,
    ) {}

    public function build(?string $document, ?string $memoryLimit): int
    {
        // putenv rather than a shell prefix: the child inherits the process environment either way,
        // and this one doesn't depend on the shell's assignment syntax.
        putenv(self::FRAGMENT_CACHE.'=1');

        $exit = 0;
        passthru($this->commandLine($document, $memoryLimit), $exit);

        return $exit;
    }

    /** Every argument escaped, including the two paths, which are the machine's rather than ours. */
    public function commandLine(?string $document, ?string $memoryLimit): string
    {
        $parts = [$this->php, $this->artisan, 'docuccino:export'];

        if ($document !== null && $document !== '') {
            $parts[] = $document;
        }

        if ($memoryLimit !== null && $memoryLimit !== '') {
            $parts[] = '--memory-limit='.$memoryLimit;
        }

        return implode(' ', array_map(escapeshellarg(...), $parts));
    }
}
