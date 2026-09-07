<?php

declare(strict_types=1);

namespace Docuccino\Inference\PhpStan\Tests\Unit;

use Docuccino\Core\Inference\SourceLocation;
use Docuccino\Inference\PhpStan\Throwing\ThrowAnalyzer;
use Docuccino\Inference\PhpStan\Throwing\UnreadStatus;
use Docuccino\Inference\PhpStan\Throwing\UnreadStatusReason;
use PhpParser\Node;
use PhpParser\NodeFinder;
use PhpParser\ParserFactory;
use ReflectionMethod;

/**
 * The pairing that keeps "the document published an unplaced status" and "the build had something to
 * say about it" from ever coming apart, and the table of reasons the second half is written from.
 */
it('gives every reason a sentence, and a remedy exactly where a reader owns one', function (UnreadStatusReason $reason, bool $hasRemedy): void {
    expect($reason->because())->not->toBe('')
        // The clause completes "…could not be read: <because>", so it is a lower-case fragment rather
        // than a sentence of its own.
        ->and($reason->because())->toMatch('/^[a-z]/')
        ->and($reason->remedy() !== null)->toBe($hasRemedy);

    if ($hasRemedy) {
        expect((string) $reason->remedy())->toEndWith('.');
    }
})->with([
    'a status argument that would not fold' => [UnreadStatusReason::DynamicArgument, true],
    'a construction that would not fold' => [UnreadStatusReason::DynamicConstruction, true],
    'a class that states no single status' => [UnreadStatusReason::UnstatedByClass, true],
    // The one whose remedy would be an edit to code the reader does not own, which is the whole
    // reason a reason carries one at all.
    'a class declared outside the project' => [UnreadStatusReason::ForeignClass, false],
]);

/**
 * A dataset only proves the rows it lists, so the set itself is held against the enum: a reason added
 * with no row would otherwise ship with its sentence unread by any test.
 */
it('answers for every reason there is', function (): void {
    $listed = [
        UnreadStatusReason::DynamicArgument,
        UnreadStatusReason::DynamicConstruction,
        UnreadStatusReason::UnstatedByClass,
        UnreadStatusReason::ForeignClass,
    ];

    expect(UnreadStatusReason::cases())->toBe($listed);
});

it('reads actionability off the file the fold read, never off the exception class', function (): void {
    $site = new SourceLocation('/app/Http/Controllers/ExportController.php', 18);

    // `abort($status)` raises the FRAMEWORK's own exception, and the expression that would not fold is
    // the application's own line. A test keyed on where the class is declared calls this unactionable.
    $abort = new UnreadStatus(
        'Symfony\\Component\\HttpKernel\\Exception\\HttpException',
        UnreadStatusReason::DynamicArgument,
        $site,
        inProjectCode: true,
    );

    // The same reason in a file nobody here can edit — a package-shipped action — names an edit the
    // reader does not own.
    $shipped = new UnreadStatus(
        'Symfony\\Component\\HttpKernel\\Exception\\HttpException',
        UnreadStatusReason::DynamicArgument,
        $site,
        inProjectCode: false,
    );

    // And the gate's other conjunct, executed rather than assumed: a reason with no remedy is silent
    // whatever file it was read in. The analyser never builds this pairing — `ForeignClass` is recorded
    // only where the class is foreign, and with `inProjectCode` false in the same breath — so this is the
    // row that says a future producer could not report one by getting the flag wrong.
    $foreign = new UnreadStatus(
        'Symfony\\Component\\HttpKernel\\Exception\\ConflictHttpException',
        UnreadStatusReason::ForeignClass,
        $site,
        inProjectCode: true,
    );

    expect($abort->isActionable())->toBeTrue()
        ->and($shipped->isActionable())->toBeFalse()
        ->and($foreign->isActionable())->toBeFalse();
});

it('names the exception, the site and the fold that gave up, in one sentence', function (): void {
    $unread = new UnreadStatus(
        'App\\Exceptions\\ExportConflictException',
        UnreadStatusReason::DynamicConstruction,
        new SourceLocation('/app/Services/ExportProbeQuery.php', 22),
        inProjectCode: true,
    );

    expect($unread->sentence())
        ->toContain('App\\Exceptions\\ExportConflictException')
        ->toContain('/app/Services/ExportProbeQuery.php:22')
        ->toContain(UnreadStatusReason::DynamicConstruction->because());
});

it('keys one notice per exception, reason and site rather than per path that reaches it', function (): void {
    $at = static fn (int $line, UnreadStatusReason $reason): UnreadStatus => new UnreadStatus(
        'App\\Exceptions\\ExportConflictException',
        $reason,
        new SourceLocation('/app/Services/ExportProbeQuery.php', $line),
        inProjectCode: true,
    );

    expect($at(22, UnreadStatusReason::DynamicConstruction)->key())
        ->toBe($at(22, UnreadStatusReason::DynamicConstruction)->key())
        ->and($at(22, UnreadStatusReason::DynamicConstruction)->key())
        ->not->toBe($at(31, UnreadStatusReason::DynamicConstruction)->key())
        ->and($at(22, UnreadStatusReason::DynamicConstruction)->key())
        ->not->toBe($at(22, UnreadStatusReason::UnstatedByClass)->key());
});

/**
 * The invariant executed rather than asserted, and at the level it actually has to hold: the ANALYSIS
 * may not hand back "no status" with nothing to report. It is checkable because the two halves are one
 * expression — the recorder is the only thing in {@see ThrowAnalyzer} that evaluates to a missing
 * status, so a null the document keys at its unplaced status is a null something filed a reason for.
 *
 * Read off the source rather than asked of the class, so it states the rule independently of whatever
 * the code currently does; a `return null` added to either status answer fails here rather than going
 * out as a response nobody can explain.
 */
it('produces a missing status in one place only, and files a reason there', function (): void {
    $file = dirname(__DIR__, 2).'/src/Throwing/ThrowAnalyzer.php';
    $ast = (new ParserFactory)->createForHostVersion()->parse((string) file_get_contents($file)) ?? [];
    $finder = new NodeFinder;

    // Every record the analyser builds…
    $records = $finder->find($ast, static fn (Node $node): bool => $node instanceof Node\Expr\New_
        && $node->class instanceof Node\Name
        && $node->class->toString() === 'UnreadStatus');

    // …is handed straight to the call that files it, so none can be built and dropped.
    $filed = [];
    foreach ($finder->find($ast, static fn (Node $node): bool => $node instanceof Node\Expr\MethodCall
        && $node->var instanceof Node\Expr\Variable
        && $node->var->name === 'this'
        && $node->name instanceof Node\Identifier
        && $node->name->toString() === 'unread') as $call) {
        /** @var Node\Expr\MethodCall $call */
        foreach ($call->getArgs() as $argument) {
            $filed[] = $argument->value;
        }
    }

    // A scan that matched nothing would pass forever: the analyser really files several.
    expect(count($records))->toBeGreaterThan(2);

    foreach ($records as $record) {
        expect(in_array($record, $filed, true))->toBeTrue();
    }

    // And the other half: the two methods that answer with a status never write the missing one
    // themselves — no `return null`, and no `null` sitting in the array shape one of them hands back.
    $isNull = static fn (?Node $node): bool => $node instanceof Node\Expr\ConstFetch
        && $node->name->toLowerString() === 'null';

    $answers = $finder->find($ast, static fn (Node $node): bool => $node instanceof Node\Stmt\ClassMethod
        && in_array($node->name->toString(), ['httpStatus', 'statusForType'], true));

    expect($answers)->toHaveCount(2);

    foreach ($answers as $answer) {
        $literals = $finder->find($answer, static fn (Node $node): bool => ($node instanceof Node\Stmt\Return_
            && $isNull($node->expr)) || ($node instanceof Node\ArrayItem && $isNull($node->value)));

        expect($literals)->toBe([]);
    }

    expect((string) (new ReflectionMethod(ThrowAnalyzer::class, 'unread'))->getReturnType())->toBe('null');
});
