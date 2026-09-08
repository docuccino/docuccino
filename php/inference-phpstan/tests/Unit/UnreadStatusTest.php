<?php

declare(strict_types=1);

namespace Docuccino\Inference\PhpStan\Tests\Unit;

use Docuccino\Core\Inference\SourceLocation;
use Docuccino\Inference\PhpStan\Throwing\StatusRead;
use Docuccino\Inference\PhpStan\Throwing\UnreadStatus;
use Docuccino\Inference\PhpStan\Throwing\UnreadStatusReason;
use ReflectionClass;
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

    // And a project file whose reason has no remedy anyone owns stays silent on the reason alone.
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

it('pairs a status with its reading and a missing status with its record', function (): void {
    $unread = new UnreadStatus(
        'App\\Exceptions\\ExportConflictException',
        UnreadStatusReason::UnstatedByClass,
        new SourceLocation('/app/Exceptions/ExportConflictException.php', 9),
        inProjectCode: true,
    );

    expect(StatusRead::of(409)->status)->toBe(409)
        ->and(StatusRead::of(409)->unread)->toBeNull()
        ->and(StatusRead::of(409)->isUnplaced())->toBeFalse()
        ->and(StatusRead::unread($unread)->status)->toBeNull()
        ->and(StatusRead::unread($unread)->unread)->toBe($unread)
        ->and(StatusRead::unread($unread)->isUnplaced())->toBeTrue();
});

/**
 * The invariant executed rather than asserted: there must be no way to build "no status" with nothing
 * to report. Both named constructors are checked above; this is the other half — that they are the
 * only two, so a fifth call site cannot quietly make the pair a third way.
 */
it('refuses to be constructed any way that could lose the reason', function (): void {
    $class = new ReflectionClass(StatusRead::class);

    $factories = array_values(array_map(
        static fn (ReflectionMethod $method): string => $method->getName(),
        array_filter(
            $class->getMethods(ReflectionMethod::IS_PUBLIC),
            static fn (ReflectionMethod $method): bool => $method->isStatic(),
        ),
    ));

    expect($class->getConstructor()?->isPrivate())->toBeTrue()
        ->and($factories)->toBe(['of', 'unread']);
});
