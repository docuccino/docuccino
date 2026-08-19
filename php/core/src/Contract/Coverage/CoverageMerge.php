<?php

declare(strict_types=1);

namespace Docuccino\Core\Contract\Coverage;

/**
 * Every coverage log under a set of directories, unioned — the whole-suite view no worker and no shard
 * can take on its own.
 *
 * A union has no order and no first writer, so the merged list is a function of what ran and nothing
 * else: the same ids come back whatever the worker count was, whichever file each id was seen in, and
 * whatever order the directories were named in. An id in twenty files counts once.
 *
 * What it will NOT do is answer from part of the input. A directory that is not there, one that holds
 * no log at all, and a file that does not read back as ids each mean the merge is INCOMPLETE, and a
 * gate that quietly measured three of four shards is worse than no gate — so those are reported and
 * {@see complete()} is false, rather than being averaged away into a number.
 *
 * @internal
 */
final readonly class CoverageMerge
{
    /**
     * @param  list<string>  $ids  every operation id exercised, deduped and sorted
     * @param  list<string>  $files  the log files that were read
     * @param  list<string>  $missing  directories that could not be read at all
     * @param  list<string>  $empty  directories that hold no coverage log
     * @param  list<string>  $unreadable  log files that could not be read, or hold something else
     */
    private function __construct(
        public array $ids,
        public array $files,
        public array $missing,
        public array $empty,
        public array $unreadable,
    ) {}

    /**
     * @param  list<string>  $directories
     */
    public static function of(array $directories): self
    {
        $seen = [];
        $files = [];
        $missing = [];
        $empty = [];
        $unreadable = [];

        foreach ($directories as $directory) {
            $logs = CoverageLog::filesIn($directory);

            if ($logs === null) {
                $missing[] = $directory;

                continue;
            }

            if ($logs === []) {
                $empty[] = $directory;

                continue;
            }

            foreach ($logs as $log) {
                $ids = self::read($log);

                if ($ids === null) {
                    $unreadable[] = $log;

                    continue;
                }

                $files[] = $log;

                foreach ($ids as $id) {
                    $seen[$id] = true;
                }
            }
        }

        $ids = array_keys($seen);
        sort($ids);
        sort($files);

        return new self($ids, $files, $missing, $empty, $unreadable);
    }

    /** Whether every directory asked for contributed, which is the only state a gate may read. */
    public function complete(): bool
    {
        return $this->missing === [] && $this->empty === [] && $this->unreadable === [];
    }

    /**
     * The ids one log file holds, or null when it holds something that is not one.
     *
     * A file is written by appending lines and by nothing else, so a line carrying a control character
     * means the file was torn or was never a log — and a torn file is a shard's worth of coverage
     * silently missing, which is the failure this whole class refuses to paper over. An EMPTY file is
     * not torn: it is a worker that exercised nothing, which is an ordinary thing for a worker to do.
     *
     * @return list<string>|null
     */
    private static function read(string $path): ?array
    {
        $contents = @file_get_contents($path);

        if ($contents === false) {
            return null;
        }

        $ids = [];
        foreach (explode("\n", $contents) as $line) {
            $id = rtrim($line, "\r");

            if ($id === '') {
                continue;
            }

            if (strlen($id) > 256 || preg_match('/[\x00-\x1f\x7f]/', $id) === 1) {
                return null;
            }

            $ids[] = $id;
        }

        return $ids;
    }
}
