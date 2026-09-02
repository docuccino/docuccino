<?php

declare(strict_types=1);

namespace Docuccino\Inference\PhpStan\Tests\Integration;

use Docuccino\Core\Draft\OperationDraft;
use Docuccino\Core\Pipeline\FragmentCache;
use Docuccino\Core\Pipeline\OperationFragment;
use Docuccino\Inference\PhpStan\Tests\Support\FixtureRunner;

/**
 * Fragment-cache soundness for the status a throw publishes: every file the answer was WRITTEN in
 * has to reach the route's dependency list, and the last guard drives a real cache to prove editing
 * one of them makes the entry stale. What the statuses are is {@see ThrowStatusTest}.
 */
beforeEach(function (): void {
    ensureFixtureAvailable(FixtureRunner::available());
});

it('depends on the file the status was written in', function (): void {
    // The status now comes out of the exception class, so editing it has to rebuild every route that
    // throws it — including the abstract base, where the next edit may put a constructor that changes
    // the answer.
    expect(throwDependencyNames('inheritedHttpStatus'))
        ->toContain('PortalUnavailableException.php')
        ->toContain('PortalException.php');
})->group('fixture');

it('depends on the file a declared exception was written in', function (): void {
    // Soundness for a throw point that carries no construction at all: the `throw` and the `@throws` that
    // surfaces it are both written in the TRAIT, so editing the trait to throw something else changes
    // what this route publishes. Only the exception class's own file was ever recorded, and a warm build
    // then went on publishing the exception the trait no longer throws.
    expect(throwDependencyNames('traitThrownStatus'))
        ->toContain('GuardsProbeState.php')
        ->toContain('ProbeStaleException.php');
})->group('fixture');

it('depends on the file a status constant a DEFAULT names is declared in', function (): void {
    // The private constructor's default is what every instance of this class carries, and the number is
    // written in another file: reflection names the constant off the declaration rather than evaluating
    // it, and that name is what puts the file on the list.
    expect(throwDependencyNames('pinnedHttpStatus'))
        ->toContain('ProbeStatuses.php')
        ->toContain('ExportRejectedException.php');
})->group('fixture');

it('depends on the file a folded status constant is declared in', function (): void {
    // The same soundness for the other half of a fold: `parent::__construct(ProbeStatuses::ARCHIVED, …)`
    // takes its status from a file the exception class does not name, and changing the constant there
    // changes every route that throws it.
    expect(throwDependencyNames('constantPinnedStatus'))
        ->toContain('ProbeStatuses.php')
        ->toContain('ExportArchivedException.php');
})->group('fixture');

it('depends on the file the factory was written in', function (): void {
    // The same soundness one hop on: the status this route publishes is now a fact of a factory body, so
    // that file has to be able to invalidate the route as well.
    expect(throwDependencyNames('factoryOverriddenStatus'))->toContain('ExportConflictException.php');
})->group('fixture');

it('invalidates a cached fragment when a file the status was read from is edited', function (string $method, string $edited): void {
    // The end of the chain, through the real cache: what the analysis reports is what a fragment stores,
    // and editing any file on that list has to make the entry stale. Without the file on the list the
    // entry stays warm, which is a route publishing a status its code no longer states.
    /** @var list<string> $dependencies */
    $dependencies = throwsAnalysis($method)['dependencyFiles'];
    $path = FixtureRunner::path($edited);
    $before = file_get_contents($path);
    expect($before)->toBeString();

    $dir = sys_get_temp_dir().'/docuccino-throw-deps-'.uniqid('', true);
    $cache = static fn (): FragmentCache => new FragmentCache(true, $dir, 't', 's', 'i');
    $key = 'throw-status';

    try {
        $cache()->put($key, new OperationFragment('/probes', 'get', (new OperationDraft)->freeze(), 'GET /probes'), $dependencies);

        // Warm to begin with — otherwise the row would pass with the whole dependency list dropped.
        expect($cache()->get($key))->not->toBeNull();

        file_put_contents($path, $before."\n// edited\n");

        expect($cache()->get($key))->toBeNull();
    } finally {
        file_put_contents($path, (string) $before);
        array_map('unlink', glob($dir.'/*') ?: []);
        @unlink($dir.'/.gitignore');
        @rmdir($dir);
    }
})->with([
    'the trait the throw is written in' => ['traitThrownStatus', 'app/Support/Concerns/GuardsProbeState.php'],
    'the file the status constant is declared in' => ['constantPinnedStatus', 'app/Support/ProbeStatuses.php'],
    'the base whose factory builds the subclass' => ['inheritedFactoryStatus', 'app/Exceptions/ProbeProblemBase.php'],
    // The same constant one spelling on: a defaulted status slot, whose value a construction leaving the
    // slot empty passes and whose declaration reflection names rather than evaluates.
    'the file a defaulted status constant is declared in' => ['pinnedHttpStatus', 'app/Support/ProbeStatuses.php'],
])->group('fixture');
