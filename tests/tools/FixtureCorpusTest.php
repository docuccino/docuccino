<?php

declare(strict_types=1);

/*
 * The throw corpus held against the fixture tree it claims to cover, and the counts the product's own
 * prose quotes from it.
 *
 * `throwCorpusControllers()` is a hand-written list, and the defect it exists to prevent — a modular
 * action going unswept while the whole file stayed green — is precisely what a hand-written list has no
 * defence against: adding a third throwing controller leaves every reconciliation guard passing, because
 * each of them scans the list rather than the tree. So the list owes a separate guard that reads the
 * source of truth, which is the tracked overlay under `tests/fixture-app/src` — tracked, so this needs no
 * provisioned fixture app and runs in the ordinary suite.
 */

/** Every controller in the tracked overlay, as the corpus's own relative paths. */
function fixtureControllerPaths(): array
{
    $found = [];
    $tree = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(fixtureSourcePath(), FilesystemIterator::SKIP_DOTS));

    foreach ($tree as $file) {
        /** @var SplFileInfo $file */
        if ($file->isFile() && str_contains($file->getFilename(), 'Controller') && $file->getExtension() === 'php') {
            $found[] = ltrim(str_replace(fixtureSourcePath(), '', $file->getPathname()), '/');
        }
    }

    sort($found);

    // A scan that matched nothing would call the corpus complete forever.
    expect(count($found))->toBeGreaterThan(20);

    return $found;
}

it('sweeps every fixture controller that writes a throw', function (): void {
    // The corpus is "the controllers written to exercise throws", and what makes one of those is that its
    // own source raises one. A controller that only CALLS a thrower is outside this rule and has to be
    // added by hand — which is the boundary, stated rather than left to be discovered.
    // A throw is a throw whatever it is built out of: the pattern that used to stand here knew
    // `throw new X` and `throw $e` and read straight past `throw X::for(…)` and `throw static::make()`,
    // which is six of the eleven throwing files in this tree. Asked of the parsed source instead.
    $throwing = [];
    foreach (fixtureControllerPaths() as $relPath) {
        if (phpRaisesThrow((string) file_get_contents(fixtureSourcePath($relPath)))) {
            $throwing[] = $relPath;
        }
    }

    $swept = array_keys(throwCorpusControllers());
    sort($swept);

    expect($throwing)->not->toBeEmpty()
        ->and($throwing)->toBe($swept);
});

it('names a class for each swept controller that the file really declares', function (): void {
    // A row naming a file that moved, or a class the file no longer declares, would reduce every sweep to
    // whatever is left of the list.
    foreach (throwCorpusControllers() as $relPath => $class) {
        expect(fixtureSourcePath($relPath))->toBeFile()
            ->and(phpDeclaredTypes((string) file_get_contents(fixtureSourcePath($relPath))))->toContain($class);
    }
});

it('states the corpus size wherever the product quotes it', function (): void {
    // Two design paragraphs disagreed about one corpus — 57 in the first, 51 in the second — which is
    // what stops a reader trusting any number either of them gives. The count is readable, so it is read.
    // Through the one action grammar the sweep uses, not a second spelling of it: two readers of what an
    // action is have already disagreed here, and a denominator read by the loser of that disagreement is
    // how a corpus looks complete while actions go unswept.
    $actions = 0;
    foreach (array_keys(throwCorpusControllers()) as $relPath) {
        $actions += count(controllerActionNames((string) file_get_contents(fixtureSourcePath($relPath))));
    }

    expect($actions)->toBeGreaterThan(20);

    $quoting = [
        'php/inference-phpstan/src/Throwing/HttpExceptionStatus.php',
        'docs/design/inference-embedding.md',
    ];

    foreach ($quoting as $relPath) {
        $text = (string) file_get_contents(dirname(__DIR__, 2).'/'.$relPath);
        $stated = [];
        // Only the phrasings that name THIS corpus: §6c's "115 actions across ... every modular
        // controller" is a different denominator and says so.
        preg_match_all('/(\d+) (?:throw actions|actions on two controllers)/', $text, $found);
        foreach ($found[1] as $number) {
            $stated[] = (int) $number;
        }

        expect($stated)->not->toBeEmpty()
            ->and(array_values(array_unique($stated)))->toBe([$actions]);
    }
});

it('answers both directions for a root the application does not ship', function (): void {
    // `fixtureDescends()` is the predicate two guards excuse a silence by, and only its TRUE answer was
    // ever exercised — a version reading `return true` passed both. The false answer is the fact they
    // lean on: a root declared only in `autoload-dev` is not descended into.
    $shipped = ['App\\' => 'app/', 'Modules\\' => './modules/'];

    expect(pathUnderShippedRoot('app/Http/Controllers/ThrowsController.php', $shipped))->toBeTrue()
        ->and(pathUnderShippedRoot('modules/Billing/LedgerReviewQuery.php', $shipped))->toBeTrue()
        ->and(pathUnderShippedRoot('tests/Support/SeedHelper.php', $shipped))->toBeFalse()
        ->and(pathUnderShippedRoot('vendor/acme/src/Client.php', $shipped))->toBeFalse()
        // A prefix is a prefix: a sibling directory whose name merely starts the same way is outside.
        ->and(pathUnderShippedRoot('application/Other.php', $shipped))->toBeFalse();
});

it('reads every spelling of an action a controller can declare', function (): void {
    // The corpus cannot prove this: no tracked controller happens to use the wider spellings today, so a
    // grammar narrowed back to the plainest one still passes every sweep. The spellings are therefore
    // stated here against source written for the purpose, and the plain one is included so a pattern that
    // matched nothing at all would fail rather than agree.
    $source = <<<'PHP_SOURCE'
    <?php

    class Probe
    {
        public function plain() {}
        final public function sealed() {}
        public static function shared() {}
        final public static function both() {}
        protected function hidden() {}
        private function alsoHidden() {}
    }
    PHP_SOURCE;

    expect(controllerActionNames($source))->toBe(['plain', 'sealed', 'shared', 'both']);
});

it('slices each action body at the next declaration it recognises', function (): void {
    // The names and the bodies come from one reader for this reason: a slicer blind to a spelling the
    // sweep sees hands a row somebody else's body, and the row judges its excuse against the wrong text.
    $source = <<<'PHP_SOURCE'
    <?php

    class Probe
    {
        public function first() { return 'ONE'; }
        final public function second() { return 'TWO'; }
    }
    PHP_SOURCE;

    $actions = controllerActions($source);

    expect(array_keys($actions))->toBe(['first', 'second'])
        ->and($actions['first'])->toContain('ONE')->not->toContain('TWO')
        ->and($actions['second'])->toContain('TWO')->not->toContain('ONE');
});
