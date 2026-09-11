<?php

declare(strict_types=1);

/*
 * The guard that keeps the contested-slot policy and the code that implements it from drifting apart,
 * in both directions.
 *
 * FORWARD: every symbol the policy names has to exist, or the section becomes a set of instructions
 * pointing at classes nobody can find.
 *
 * BACKWARD — the half that matters more: every production file that POINTS AT the section has to be
 * one the section names. That is what makes the list read the source of truth rather than a
 * hand-maintained memory of it. A new producer copying the pointer out of an existing one, which is
 * how these spread, arrives here as a failure naming the file, instead of being silently outside a
 * policy that claims to cover it.
 */

/** The heading the policy is written under, and the phrase every site cites it by. */
function contestedSlotHeading(): string
{
    return '### A contested published slot: merged member-wise, or handed whole to one contributor';
}

function contestedSlotSection(): string
{
    $page = (string) file_get_contents(dirname(__DIR__, 2).'/docs/design/uir-and-extensions.md');
    $start = strpos($page, contestedSlotHeading());

    expect($start)->not->toBeFalse('the design doc no longer carries the contested-slot policy');

    $rest = substr($page, (int) $start + strlen(contestedSlotHeading()));
    $end = strpos($rest, "\n## ");

    return $end === false ? $rest : substr($rest, 0, $end);
}

/** @return list<string> */
function contestedSlotSourceFiles(): array
{
    $root = dirname(__DIR__, 2);
    $files = [];

    foreach (['core', 'laravel', 'inference-phpstan'] as $package) {
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(
            $root.'/php/'.$package.'/src',
            FilesystemIterator::SKIP_DOTS,
        ));

        foreach ($iterator as $entry) {
            if ($entry instanceof SplFileInfo && $entry->getExtension() === 'php') {
                $files[] = $entry->getPathname();
            }
        }
    }

    sort($files);

    return $files;
}

it('names only symbols that exist, so the policy can be followed to the code', function (): void {
    preg_match_all('/`([A-Z][A-Za-z0-9]*(?:\\\\[A-Z][A-Za-z0-9]*)+)(?:::([a-zA-Z]+)\(\))?`/', contestedSlotSection(), $matches, PREG_SET_ORDER);

    $missing = [];
    $found = 0;

    foreach ($matches as $match) {
        $class = null;
        foreach (['Docuccino\\Core\\', 'Docuccino\\Laravel\\'] as $prefix) {
            if (class_exists($prefix.$match[1])) {
                $class = $prefix.$match[1];

                break;
            }
        }

        if ($class === null) {
            $missing[] = $match[1];

            continue;
        }

        $found++;

        if (($match[2] ?? '') !== '' && ! method_exists($class, $match[2])) {
            $missing[] = $match[1].'::'.$match[2].'()';
        }
    }

    // A pattern that stopped matching would report nothing missing forever, so the count it did match
    // is asserted beside the emptiness: the section works through five shapes and cites more classes
    // than that between them.
    expect($missing)->toBe([])
        ->and($found)->toBeGreaterThanOrEqual(8);
});

it('names every production file that cites it, so the policy is never short', function (): void {
    $root = dirname(__DIR__, 2).'/';
    $section = contestedSlotSection();

    $citing = [];
    foreach (contestedSlotSourceFiles() as $file) {
        $source = (string) file_get_contents($file);

        if (! str_contains($source, 'A contested published slot')) {
            continue;
        }

        $short = basename($file, '.php');
        $citing[] = str_replace($root, '', $file);

        if (str_contains($section, $short)) {
            array_pop($citing);
        }
    }

    // Same reason as above: a scan finding nothing must not read as a clean bill of health, so the
    // number of files that DO cite the section is asserted beside the ones the section fails to name.
    $cited = array_filter(
        contestedSlotSourceFiles(),
        static fn (string $file): bool => str_contains((string) file_get_contents($file), 'A contested published slot'),
    );

    expect($citing)->toBe([])
        ->and(count($cited))->toBeGreaterThanOrEqual(5);
});
