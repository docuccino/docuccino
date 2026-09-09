<?php

declare(strict_types=1);

namespace Docuccino\Inference\PhpStan\Throwing;

use Docuccino\Core\Diagnostics\Diagnostic;
use Docuccino\Core\Diagnostics\Severity;
use Docuccino\Core\Provenance\MessagePaths;

/**
 * What one analysis stopped short of because the descend scope excluded a file the application
 * declares, and the notices those records publish.
 *
 * The mirror of {@see UnreadStatuses} for the other end of the throw path: that one reports a response
 * the document carries with a status nobody read, this one a response the document may not carry at
 * all. Both are the analyser's PUBLISHED half, so both live outside {@see ThrowAnalyzer}, and both
 * speak at `info` — the recovery channel, where the document came out vaguer than the code and the
 * build says where. Bounding descent is a correct outcome rather than a defect, which is what keeps
 * this off the rung a severity gate fails on.
 *
 * There is no actionability filter here, and that is a property of the recording rather than an
 * omission — {@see SkippedDescent} is only ever built for a file the application's own autoload map
 * declares, so the remedy is always the reader's to make. A narrowing that costs nothing records
 * nothing: the population is calls whose bodies really were skipped, not scopes that happen to be
 * narrow.
 *
 * @internal
 */
final class SkippedDescents
{
    /** @var array<string, SkippedDescent> by {@see SkippedDescent::key()} */
    private array $records = [];

    public function record(SkippedDescent $skipped): void
    {
        $this->records[$skipped->key()] = $skipped;
    }

    /**
     * One notice per skipped call, in the records' own order rather than the order the analysis met
     * them, with the paths crossed into message form the way every other engine message is.
     *
     * @return list<Diagnostic>
     */
    public function diagnostics(MessagePaths $labels): array
    {
        $keys = array_keys($this->records);
        sort($keys);

        $diagnostics = [];
        foreach ($keys as $key) {
            $diagnostics[] = new Diagnostic(
                severity: Severity::Info,
                code: 'inference.descend-scope-narrowed',
                message: $labels->relative($this->records[$key]->sentence()),
                help: 'engine.project_paths in docuccino.yaml bounds how far inference walks. Remove the key to descend into every PSR-4 root your composer.json declares under `autoload`, or add this directory to the list.',
            );
        }

        return $diagnostics;
    }
}
