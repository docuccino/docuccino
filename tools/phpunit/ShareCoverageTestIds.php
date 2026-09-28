<?php

declare(strict_types=1);

namespace Docuccino\Tools\PhpUnit;

use PHPUnit\Event\EventFacadeIsSealedException;
use PHPUnit\Event\TestRunner\ExecutionFinished;
use PHPUnit\Event\TestRunner\ExecutionFinishedSubscriber;
use PHPUnit\Runner\CodeCoverage;
use PHPUnit\Runner\Extension\Extension;
use PHPUnit\Runner\Extension\Facade;
use PHPUnit\Runner\Extension\ParameterCollection;
use PHPUnit\TextUI\Configuration\Configuration;
use SebastianBergmann\CodeCoverage\Data\ProcessedCodeCoverageData;

/**
 * Stores each test id in the collected line coverage once, with every repeat a reference to it, so a
 * parallel worker's serialized coverage — which spells a plain string out at every occurrence — stays
 * the size of what it means. Lossless: every report reads the same ids and counts. Why it is needed is
 * in docs/testing.md §"Why the coverage gate shares test ids".
 */
final class ShareCoverageTestIds implements ExecutionFinishedSubscriber, Extension
{
    public function bootstrap(Configuration $configuration, Facade $facade, ParameterCollection $parameters): void
    {
        if (! $configuration->hasCoverageReport()) {
            return;
        }

        try {
            $facade->registerSubscriber($this);
        } catch (EventFacadeIsSealedException) {
            // The parallel runner's parent bootstraps extensions after sealing; it runs no tests and
            // collects nothing, so there is nothing of its own to share.
        }
    }

    /** Runs before the runner writes any coverage report, in a parallel worker and a serial run alike. */
    public function notify(ExecutionFinished $event): void
    {
        if (CodeCoverage::instance()->isActive()) {
            self::share(CodeCoverage::instance()->codeCoverage()->getData(true));
        }
    }

    public static function share(ProcessedCodeCoverageData $data): void
    {
        $slots = [];
        $coverage = $data->lineCoverage();

        foreach ($coverage as &$lines) {
            foreach ($lines as &$ids) {
                if ($ids === null) {
                    continue;
                }

                foreach ($ids as $i => $id) {
                    if (! array_key_exists($id, $slots)) {
                        $slots[$id] = $id;
                    }

                    $ids[$i] = &$slots[$id];
                }
            }
            unset($ids);
        }
        unset($lines);

        $data->setLineCoverage($coverage);
    }
}
