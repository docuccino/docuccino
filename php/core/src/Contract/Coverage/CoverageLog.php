<?php

declare(strict_types=1);

namespace Docuccino\Core\Contract\Coverage;

use Docuccino\Core\Support\Directory;

/**
 * One process's share of a coverage run: a file of operation ids, appended to as the suite meets them.
 *
 * There is no lock here, and that is the whole difference from the response recorder's shared ledger.
 * A recording is a value several workers contest — the best body for one operation — so they have to
 * take turns over one file. Coverage is a SET UNION: an id was exercised or it was not, two workers
 * seeing the same one adds nothing to reconcile, and a worker's own file has exactly one writer. So
 * every process appends to a file of its own and {@see CoverageMerge} unions them after the run,
 * where the timing question a worker cannot answer no longer needs asking.
 *
 * @internal
 */
final readonly class CoverageLog
{
    public const string EXTENSION = '.ids';

    public function __construct(public string $directory, public string $file) {}

    /**
     * The log this process writes.
     *
     * The name carries the worker token wherever the runner sets one, because `w3.…` is worth more to
     * whoever opens the directory than a hash is — but it carries the pid and four random bytes BESIDE
     * it rather than instead of them, because a token is not unique. Run `--shard=1/4` and
     * `--shard=2/4` on one machine and both have a worker `1`; one silently overwriting the other is
     * exactly the false gap this feature exists to stop. Nothing is detected, either: a runner that
     * sets no token is the ordinary single-process case, not an error, and a runner nobody has heard
     * of participates by writing a file like everyone else.
     *
     * The cost of unique-per-process names is that a second run ADDS files rather than replacing them,
     * which is why `docuccino:coverage --reset` exists and why the documented recipe runs it first. No
     * determinism is spent: a name never reaches a document, and the merged report is a sorted union
     * that does not know what the files were called.
     */
    public static function for(string $directory, ?string $worker = null): self
    {
        return new self($directory, sprintf(
            '%s.%d.%s%s',
            self::slug($worker ?? 'main'),
            getmypid() === false ? 0 : getmypid(),
            // random_int over bin2hex(random_bytes(…)): the oldest analyser CI resolves types
            // random_bytes as mixed, and it carries more entropy per character besides.
            dechex(random_int(0, PHP_INT_MAX)),
            self::EXTENSION,
        ));
    }

    public function path(): string
    {
        return $this->directory.'/'.$this->file;
    }

    /**
     * Append ids to this process's file, one per line. False when the directory or the write refused,
     * which the caller treats as "this run logged nothing" rather than as a failed test.
     *
     * @param  list<string>  $ids
     */
    public function append(array $ids): bool
    {
        if ($ids === []) {
            return true;
        }

        if (! Directory::ensure($this->directory)) {
            return false;
        }

        return @file_put_contents($this->path(), implode("\n", $ids)."\n", FILE_APPEND) !== false;
    }

    /**
     * Every log file under a directory, sorted, or null when the directory is not one that can be
     * read — which the merge reports as a shard it is missing rather than as an absence of coverage.
     *
     * It descends subdirectories, so a directory of downloaded CI artifacts merges as one path, and it
     * refuses to descend a LINK: `is_link()` is asked before `is_dir()`, which answers true for a link
     * to one. That matters because the reset path deletes what this returns.
     *
     * @return list<string>|null
     */
    public static function filesIn(string $directory): ?array
    {
        if (! is_dir($directory) || is_link($directory)) {
            return null;
        }

        $entries = @scandir($directory);

        if ($entries === false) {
            return null;
        }

        sort($entries);

        $files = [];
        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            $path = $directory.'/'.$entry;

            if (is_link($path)) {
                continue;
            }

            if (is_dir($path)) {
                $files = array_merge($files, self::filesIn($path) ?? []);

                continue;
            }

            if (str_ends_with($entry, self::EXTENSION)) {
                $files[] = $path;
            }
        }

        return $files;
    }

    /** A worker token as a filename fragment: the runner chose the string, not us. */
    private static function slug(string $value): string
    {
        $slug = substr((string) preg_replace('/[^A-Za-z0-9_-]/', '-', $value), 0, 32);

        return $slug === '' ? 'main' : $slug;
    }
}
