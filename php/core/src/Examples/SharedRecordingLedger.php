<?php

declare(strict_types=1);

namespace Docuccino\Core\Examples;

use Docuccino\Core\Support\AtomicFile;
use JsonException;

/**
 * The ledger for a suite split across worker processes: the session lives in a scratch file beside an
 * exclusive lock, and every read-compare-write of a recording happens inside it.
 *
 * A recording is per-operation, so a worker recording one needs nothing from any other worker — only
 * that the two of them do not write over each other. The lock gives that, and
 * {@see RecordedExample::outranks()} gives the rest: it is a total order on the bodies themselves, so
 * the best of a set is the same whichever worker met which member of it, and the surviving file is a
 * function of the responses the suite produced rather than of the order the workers raced in.
 *
 * The lock is a file of its own rather than the recording, because the recording is replaced by a
 * rename: a lock held on it would be a lock on an inode the next writer has already replaced. Both it
 * and the session sit under the system temp directory keyed by the recordings directory and the RUN,
 * so a later run of the same suite starts from the file as it stands rather than from what the last
 * one was accumulating, and neither ever appears in the tree an author commits.
 *
 * @internal
 */
final class SharedRecordingLedger extends RecordingLedger
{
    public function __construct(
        private readonly RecordingStore $store,
        private readonly string $runKey,
        private readonly ?string $scratchRoot = null,
    ) {}

    /**
     * @throws UnlockableRecording when the writers cannot be serialised, which is never answered by
     *                             writing anyway
     */
    protected function commit(string $operationId, string $endpoint, RecordedExample $example): void
    {
        $file = RecordingStore::fileNameFor($operationId);

        if ($file === null) {
            return;
        }

        $directory = $this->scratch();

        if (! is_dir($directory) && ! @mkdir($directory, 0777, true) && ! is_dir($directory)) {
            throw UnlockableRecording::directory($directory);
        }

        $session = $directory.'/'.$file;
        $lock = $session.'.lock';
        $handle = @fopen($lock, 'c');

        if ($handle === false) {
            throw UnlockableRecording::unopenable($lock);
        }

        try {
            if (! flock($handle, LOCK_EX)) {
                throw UnlockableRecording::unlockable($lock);
            }

            $merged = ($this->read($session) ?? RecordingSession::opening($this->store->read($operationId)))->with($example);

            if ($this->store->put($merged->recording($operationId, $endpoint))) {
                $this->write($session, $merged);
            }

            flock($handle, LOCK_UN);
        } finally {
            fclose($handle);
        }
    }

    /** The run's own scratch directory: one per recordings directory per run, never inside the tree. */
    private function scratch(): string
    {
        $root = $this->scratchRoot ?? sys_get_temp_dir();
        $run = (string) preg_replace('/[^A-Za-z0-9._-]/', '-', $this->runKey);

        return $root.'/docuccino-recordings-'.substr(sha1($this->store->directory), 0, 16).'-'.$run;
    }

    /** The session this run has accumulated, or null when it has not started one — or left a torn file. */
    private function read(string $path): ?RecordingSession
    {
        $contents = @file_get_contents($path);

        if ($contents === false) {
            return null;
        }

        try {
            $decoded = json_decode($contents, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return null;
        }

        if (! is_array($decoded) || array_is_list($decoded)) {
            return null;
        }

        /** @var array<string, mixed> $decoded */
        return RecordingSession::fromArray($decoded);
    }

    /** Written the way the recording itself is, so a worker reading it never sees half of one. */
    private function write(string $path, RecordingSession $session): void
    {
        $json = json_encode($session->toArray());

        if ($json !== false) {
            AtomicFile::write($path, $json);
        }
    }
}
