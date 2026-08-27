<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Testing;

use Docuccino\Core\Contract\Coverage\CoverageLog;
use Docuccino\Core\Contract\Coverage\CoverageReport;
use Docuccino\Laravel\Support\CoverageLogPath;
use Docuccino\Laravel\Testing\Contracts\ContractObserver;

/**
 * Which documented responses this process exercised, by stable operation id and status — never by path,
 * for the reason {@see CoverageReport} gives.
 *
 * A process is all it can speak for, which is the whole shape of the feature: a worker sees its own
 * share of the suite and nothing else, and no worker can know when the others have finished. So
 * {@see logTo()} writes what this one exercised to a file of its own, and `docuccino:coverage` unions
 * the files after the run — where the whole suite has, by then, definitely finished.
 */
final class CoverageRecorder implements ContractObserver
{
    /** @var array<string, true> */
    private array $entries = [];

    private bool $logging = false;

    private ?string $directory = null;

    private ?CoverageLog $log = null;

    /**
     * A response counts only where the response half was actually checked. `assertValidRequest()` proves
     * nothing about what came back, so it records the operation as reached and no response of it —
     * counting one there is exactly the too-generous number this whole report exists to stop.
     */
    public function observed(ObservedExchange $exchange): void
    {
        $id = $exchange->operationId();

        if ($id !== null) {
            $this->record($id, $exchange->result->response === null ? null : $exchange->status());
        }
    }

    /**
     * Record one exercised response, by operation id and the status it answered. Pass no status for an
     * operation the run reached without proving any response of it.
     *
     * Anything that is not an entry is dropped rather than recorded: this is public, a log line is held
     * to the entry shape when it is read back, and a caller passing a stray string would otherwise
     * condemn the whole file its process wrote. Nothing is lost by dropping it — an id that is not an
     * operation's matches no operation in the report either.
     */
    public function record(string $id, ?int $status = null): void
    {
        $entry = CoverageLog::entry($id, $status);

        if ($entry === null || isset($this->entries[$entry])) {
            return;
        }

        $this->entries[$entry] = true;

        if ($this->logging) {
            $this->log()->append([$entry]);
        }
    }

    /**
     * Start writing this process's entries to a coverage log, for `docuccino:coverage` to merge.
     *
     * Pass a directory to override `coverage.log`. Each entry is appended the first time it is seen, so
     * a suite that crashes half way still leaves behind what it had reached.
     */
    public function logTo(?string $directory = null): self
    {
        $this->logging = true;
        $this->directory = $directory;
        $this->log = null;

        return $this;
    }

    /** The file this process writes, or null when it is not logging — for a bootstrap that wants to say so. */
    public function logPath(): ?string
    {
        return $this->logging ? $this->log()->path() : null;
    }

    /**
     * What this process exercised, as coverage log entries. Sorted, so two runs that exercised the same
     * responses hand back the same list whatever order the tests ran in.
     *
     * @return list<string>
     */
    public function exercised(): array
    {
        $entries = array_keys($this->entries);
        sort($entries);

        return $entries;
    }

    /** Forget what this process recorded. What it already wrote to its log stays written. */
    public function forget(): void
    {
        $this->entries = [];
    }

    /**
     * Resolved on first use, because a test bootstrap constructs the recorder before there is a
     * container to ask where the directory is.
     */
    private function log(): CoverageLog
    {
        return $this->log ??= CoverageLog::for(
            CoverageLogPath::resolve(ApiContract::build()->config(), base_path(), $this->directory),
            ParallelRun::worker(),
        );
    }
}
