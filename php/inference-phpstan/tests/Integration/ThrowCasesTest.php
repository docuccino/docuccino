<?php

declare(strict_types=1);

namespace Docuccino\Inference\PhpStan\Tests\Integration;

use Docuccino\Core\Draft\OperationDraft;
use Docuccino\Core\Pipeline\FragmentCache;
use Docuccino\Core\Pipeline\OperationFragment;
use Docuccino\Inference\PhpStan\Tests\Support\FixtureRunner;

/**
 * The throw-analysis scorecard against the real engine: abort status folding, registry
 * enrichment + rescue, bounded descent, `@throws` trust, catch subtraction, and
 * exception identity by (fqcn, status).
 */
beforeEach(function (): void {
    ensureFixtureAvailable(FixtureRunner::available());
});

/**
 * @return array<string, mixed>
 */
function throwsAnalysis(string $method): array
{
    return FixtureRunner::analyze(
        'app/Http/Controllers/ThrowsController.php',
        'App\\Http\\Controllers\\ThrowsController',
        $method,
    );
}

/**
 * @return list<string> signal-disposition exceptions as "ShortName@status"
 */
function signalThrows(string $method): array
{
    $analysis = throwsAnalysis($method);

    $out = [];
    /** @var list<array<string, mixed>> $throws */
    $throws = $analysis['throws'];
    foreach ($throws as $throw) {
        if (($throw['disposition'] ?? null) !== 'signal') {
            continue;
        }
        $fqcn = (string) $throw['exceptionFqcn'];
        $pos = strrpos($fqcn, '\\');
        $short = $pos !== false ? substr($fqcn, $pos + 1) : $fqcn;
        $out[] = $short.'@'.($throw['httpStatusHint'] ?? 'null');
    }
    sort($out);

    return $out;
}

dataset('throw cases', [
    'abort + abort_if, both statuses folded' => ['abortAction', ['HttpException@403', 'HttpException@404']],
    // The same two calls with the status named rather than counted. PHPStan hands throw points the
    // NORMALIZED call, so a named argument already sits in the position the registry indexes — pinned
    // here because the day that stops being true, both statuses vanish without a word.
    'abort + abort_if, statuses named' => ['namedAbortAction', ['HttpException@418', 'HttpException@451']],
    'authorize → 403' => ['authorizeAction', ['AuthorizationException@403']],
    'static findOrFail rescued → 404' => ['findOrFailAction', ['ModelNotFoundException@404']],
    'inline validate → 422' => ['validateAction', ['ValidationException@422']],
    '2-deep descent, no @throws' => ['deepUndeclared', ['OutOfStockException@500', 'RuntimeException@500']],
    '@throws trusted, deeper hidden' => ['deepDeclared', ['OutOfStockException@500']],
    'vendor any-throwable = no API error' => ['anyThrowableNoise', []],
    'caught subtracted, escaping surfaced' => ['tryCatch', ['RuntimeException@500']],
    // The registry is keyed on a bare method name, so an app's own validate() is exactly where a guess
    // could overrule a truth: the callee is project code we read, so its own exception stands and no
    // ValidationException/422 is invented for it.
    "the app's own validate() keeps its own exception" => ['projectValidate', ['OutOfStockException@500']],
    // An exception that IS an HTTP status states it in its own constructor, where no name-keyed table can
    // see it. Each row is one of the shapes an application writes that in, and 500 — the answer a lookup
    // miss used to give all three — would be a failure the server does not have.
    'a status pinned through a private constructor default' => ['pinnedHttpStatus', ['ExportRejectedException@422']],
    'a status pinned as a literal two classes up' => ['inheritedHttpStatus', ['PortalUnavailableException@503']],
    'a status written at the throw, no constructor of its own' => ['httpStatusAtThrowSite', ['ExportLockedException@423']],
    'a status written at the throw, its argument NAMED' => ['namedHttpStatusAtThrowSite', ['ExportLockedException@423']],
    // The same default behind a PUBLIC constructor is no pin for the CLASS — any caller may pass another —
    // and it is still what THIS construction passes, because it left the slot empty. The pair below is the
    // point: the same `new`, one hop apart, and a document that answered them differently.
    'a public constructor default, at the throw' => ['defaultedHttpStatusAtThrowSite', ['ExportBlockedException@409']],
    'the same construction inside the factory the throw names' => ['defaultedHttpStatusInFactory', ['ExportBlockedException@409']],
    // A constructor that normalises the status it was handed: `none()` really builds a 400, so the 422 the
    // default names is a status the code does not state and no status at all is the honest answer.
    'a constructor that moves the status it was handed' => ['movedHttpStatus', ['ExportPartialException@null']],
    // …and the same defect one statement later, which is the row the FOLD SCOPE decides: folding the
    // forwarded argument in the body's end scope answers the 500 assigned after the parent call, a status
    // nothing was ever built with. Folding it at the call, where it is written, answers nothing at all.
    'a constructor that reuses the status after forwarding it' => ['supersededHttpStatus', ['ExportSupersededException@null']],
    // Nothing folds, which is what the diagnostic below is raised for.
    'a factory that builds the class two ways' => ['unreadHttpStatus', ['ExportConflictException@null']],
    // A vendor exception the application throws itself is still the application's error; its status is
    // written where PHPStan strips the body, so it is documented without one rather than at a made-up 500.
    'a vendor exception thrown deliberately' => ['vendorHttpStatusAtThrowSite', ['ConflictHttpException@null']],
    // …and one only DECLARED by a vendor method is plumbing: being an HttpException subclass is not itself
    // a status, so nothing here is promoted to an API error.
    'a vendor @throws of a vendor HttpException subclass' => ['vendorDeclaredHttpStatus', []],
    // A class that pins nothing because its FACTORIES choose. The throw names one, and the class constant
    // it builds with folds through the factory's own scope like the literal beside it would.
    'a status the factory named at the throw builds with' => ['factoryHttpStatus', ['ExportUnsupportedException@422']],
    // The pair that makes the point: one class, two factories, two statuses, on two operations. A reader of
    // the class alone can only answer null for both, and answering 500 would invent a failure twice over.
    'one factory of a two-status class' => ['factoryDefaultedStatus', ['ExportConflictException@409']],
    'its sibling, the same class at another status' => ['factoryOverriddenStatus', ['ExportConflictException@403']],
    // Where the throw point carries NO construction, the class's own is the only thing left that can
    // answer — and a class that builds itself exactly one way has said its status once, in that one place.
    // Every row here surfaced the exception with no status at all until the class was asked.
    'a class that builds itself one way, thrown from a trait' => ['traitThrownStatus', ['ProbeStaleException@409']],
    'the same class reached by a rethrow' => ['rethrownStatus', ['ProbeStaleException@409']],
    // A closure is its own scope, so the analysed method's throw point is the CALL that was handed the
    // closure — a bare `Throwable`, or nothing at all. The status is written one scope in.
    'a status written at a throw inside a closure' => ['closureThrownStatus', ['ExportLockedException@423']],
    'a factory named at a throw inside a closure' => ['closureFactoryThrownStatus', ['ExportUnsupportedException@422']],
    'a closure held in a local before it is handed over' => ['heldClosureThrownStatus', ['ExportLockedException@423']],
    // The boundary of that hop, pinned rather than described: PHPStan models an arrow function with no
    // statement result, so it has no throw points to read and nothing is surfaced.
    'the same throw in an arrow function' => ['arrowThrownStatus', []],
    // …and the other boundary, counted rather than described: a closure spends one of descent's own
    // depth budget, so the throw three closures in is read and the 410 one nesting behind it is not.
    // The counted throw is written BEFORE the closure it is measured against, and that is load-bearing:
    // `transaction()` is generic over its callback's return from Laravel 13 on, so a closure that only
    // throws makes the call `never` and everything after it dead code the application cannot reach.
    // Written the other way round this row read 423 on Laravel 12 and nothing at all on Laravel 13.
    'closures nested past the descent budget' => ['nestedClosureThrownStatus', ['ExportLockedException@423']],
    // And the guard on all of it: a construction that PRESENTED itself and would not fold has said the
    // response is whatever was chosen at run time. What the class's own factory agrees on — a 409 — is no
    // evidence for THIS throw, so no status is the honest answer.
    'a construction whose status is chosen at run time' => ['runtimeStatusAtThrowSite', ['ExportBlockedException@null']],
    // …and the same guard where the construction is one ASSIGNMENT behind the throw, which is how a body
    // that decorates an exception before throwing it is written. The 451 is the status the code built,
    // and the class's own 409 would be a status this response never has.
    'a construction one assignment behind the throw' => ['heldConstructionAtThrowSite', ['ExportBlockedException@451']],
    'the same, with its status chosen at run time' => ['heldRuntimeConstructionAtThrowSite', ['ExportBlockedException@null']],
    // A class is built by its BASE too: `new static(503)` one class up builds this one, so a subclass
    // adding nothing still has a status, and one adding a factory of its own has two and states neither.
    'a factory the subclass inherits from its base' => ['inheritedFactoryStatus', ['ExportRelocatedException@503']],
    'a class its own base and its own factory build differently' => ['inheritedAgreementStatus', ['ExportOfflineException@null']],
    // Two closures handed to one call on ONE line are two bodies and two errors; a reader keying them by
    // line resolves both to the second, and the first error leaves the document without a word.
    'two closures written on one line' => ['pairedClosureThrownStatus', ['ExportLockedException@423', 'ExportUnsupportedException@422']],
    // A status pinned through a constant declared in another file entirely.
    'a status pinned through another file\'s constant' => ['constantPinnedStatus', ['ExportArchivedException@415']],
]);

it('surfaces exactly the expected API errors', function (string $method, array $expected): void {
    sort($expected);

    expect(signalThrows($method))->toBe($expected);
})->with('throw cases')->group('fixture');

/**
 * @return list<string> the messages of every unread-HTTP-status notice the analysis raised
 */
function unreadStatusDiagnostics(string $method): array
{
    /** @var list<array<string, mixed>> $diagnostics */
    $diagnostics = throwsAnalysis($method)['diagnostics'];

    $out = [];
    foreach ($diagnostics as $diagnostic) {
        if (($diagnostic['code'] ?? null) === 'inference.http-exception-status-unread') {
            $out[] = (string) $diagnostic['message'];
        }
    }

    return $out;
}

it('names the class whose HTTP status it could not read', function (string $method, string $fqcn): void {
    $reported = unreadStatusDiagnostics($method);

    expect($reported)->toHaveCount(1)
        ->and($reported[0])->toContain($fqcn);
})->with([
    'a factory that builds the class two ways' => ['unreadHttpStatus', 'App\\Exceptions\\ExportConflictException'],
    'a constructor that moves the status it was handed' => ['movedHttpStatus', 'App\\Exceptions\\ExportPartialException'],
    'a constructor that reuses the status after forwarding it' => ['supersededHttpStatus', 'App\\Exceptions\\ExportSupersededException'],
    // A status chosen at run time, which is the one thing the notice's help text asks the author to
    // change — and the class's own agreement may not answer over the top of it.
    'a construction whose status is chosen at run time' => ['runtimeStatusAtThrowSite', 'App\\Exceptions\\ExportBlockedException'],
    'the same construction one assignment behind the throw' => ['heldRuntimeConstructionAtThrowSite', 'App\\Exceptions\\ExportBlockedException'],
    // A class its base and its own factory build at two statuses, reached where nothing says which ran.
    'a class built two ways, reached with no construction' => ['inheritedAgreementStatus', 'App\\Exceptions\\ExportOfflineException'],
])->group('fixture');

it('says nothing where the status read, and nothing about a class the author does not own', function (string $method): void {
    expect(unreadStatusDiagnostics($method))->toBe([]);
})->with([
    'pinnedHttpStatus',
    'inheritedHttpStatus',
    'httpStatusAtThrowSite',
    'namedHttpStatusAtThrowSite',
    'defaultedHttpStatusAtThrowSite',
    'defaultedHttpStatusInFactory',
    'factoryHttpStatus',
    'factoryDefaultedStatus',
    'factoryOverriddenStatus',
    // The two vendor shapes: the status is unread in both, and the remedy the notice names is an edit to
    // `vendor/` — the non-actionable firing that trains a reader to ignore the channel.
    'vendorHttpStatusAtThrowSite',
    'vendorDeclaredHttpStatus',
    // And nothing for a plain domain exception either: it is not an HttpException, so there is no status
    // on it to have failed to read.
    'deepUndeclared',
    // The shapes the class now answers for itself, each of which used to earn a notice naming a class
    // whose author had already written the status exactly once.
    'traitThrownStatus',
    'rethrownStatus',
    'closureThrownStatus',
    'closureFactoryThrownStatus',
    'heldClosureThrownStatus',
    // Nothing is surfaced from an arrow function at all, so there is no class to name.
    'arrowThrownStatus',
    'nestedClosureThrownStatus',
    // The construction one assignment behind the throw, and the base's factory the subclass inherits:
    // both name a status, so neither class is one the author is asked about.
    'heldConstructionAtThrowSite',
    'inheritedFactoryStatus',
    'pairedClosureThrownStatus',
    'constantPinnedStatus',
])->group('fixture');

it('answers the same status whether the construction is at the throw or one hop inside a factory', function (): void {
    // "Covering is not agreeing": the two spellings each had a row, and what neither asked was whether they
    // agree — they did not, by 409 against nothing at all. The rule is stated here rather than asked of the
    // code: `new ExportBlockedException` leaves the status slot empty, PHP fills it with the 409 written on
    // the constructor, and where the same `new` sits is not a fact about the response.
    expect(signalThrows('defaultedHttpStatusAtThrowSite'))
        ->toBe(signalThrows('defaultedHttpStatusInFactory'))
        ->and(signalThrows('defaultedHttpStatusAtThrowSite'))->toBe(['ExportBlockedException@409']);
})->group('fixture');

it('answers the same status whether the throw is written inline or inside a closure', function (): void {
    // The same "covering is not agreeing" rule one scope in: each spelling has a row above, and neither
    // asks whether they agree. The rule is stated here rather than read off the code — where a `throw` is
    // written is not a fact about the response, so a closure the method hands to a callee that runs it
    // owes the same answer the method's own body would.
    expect(signalThrows('closureThrownStatus'))
        ->toBe(signalThrows('httpStatusAtThrowSite'))
        ->and(signalThrows('closureThrownStatus'))->toBe(['ExportLockedException@423'])
        ->and(signalThrows('heldClosureThrownStatus'))->toBe(signalThrows('closureThrownStatus'));
})->group('fixture');

it('names the closure the throw was written in', function (): void {
    // The chain is what an author is shown when they go looking, and a throw two scopes down that reports
    // only the action names a line with no `throw` on it.
    /** @var list<array<string, mixed>> $throws */
    $throws = throwsAnalysis('closureThrownStatus')['throws'];

    /** @var list<array<string, mixed>> $chain */
    $chain = $throws[0]['callChain'];
    $symbols = array_map(static fn (array $frame): string => (string) $frame['symbol'], $chain);

    expect($symbols)->toBe([
        'ThrowsController::closureThrownStatus',
        'ThrowsController::closureThrownStatus::{closure}',
    ]);
})->group('fixture');

it('depends on the file the status was written in', function (): void {
    // Fragment-cache soundness: the status now comes out of the exception class, so editing it has to
    // rebuild every route that throws it — including the abstract base, where the next edit may put a
    // constructor that changes the answer.
    /** @var list<string> $files */
    $files = throwsAnalysis('inheritedHttpStatus')['dependencyFiles'];
    $names = array_map(static fn (string $file): string => basename($file), $files);

    expect($names)->toContain('PortalUnavailableException.php')
        ->and($names)->toContain('PortalException.php');
})->group('fixture');

/**
 * The basenames one action's analysis reported as its dependency set.
 *
 * @return list<string>
 */
function throwDependencyNames(string $method): array
{
    /** @var list<string> $files */
    $files = throwsAnalysis($method)['dependencyFiles'];

    return array_map(static fn (string $file): string => basename($file), $files);
}

it('depends on the file a declared exception was written in', function (): void {
    // Fragment-cache soundness for a throw point that carries no construction at all: the `throw` and the
    // `@throws` that surfaces it are both written in the TRAIT, so editing the trait to throw something
    // else changes what this route publishes. Only the exception class's own file was ever recorded, and
    // a warm build then went on publishing the exception the trait no longer throws.
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

it('depends on the file the factory was written in', function (): void {
    // The same soundness one hop on: the status this route publishes is now a fact of a factory body, so
    // that file has to be able to invalidate the route as well.
    /** @var list<string> $files */
    $files = throwsAnalysis('factoryOverriddenStatus')['dependencyFiles'];
    $names = array_map(static fn (string $file): string => basename($file), $files);

    expect($names)->toContain('ExportConflictException.php');
})->group('fixture');
