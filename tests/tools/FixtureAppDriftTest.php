<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2).'/tools/fixture-app-drift.php';

/*
 * The guard that tells a developer their fixture app is stale rather than letting five engine tests
 * fail as if their own change had broken something. Trees are synthesized in a temp dir; the real
 * fixture app is never touched.
 */
function driftTree(string $label): string
{
    $root = sys_get_temp_dir().'/docuccino-drift-'.$label.'-'.uniqid();

    mkdir($root.'/src/app/Http', recursive: true);
    mkdir($root.'/src/modules/Billing', recursive: true);
    mkdir($root.'/app/app/Http', recursive: true);
    mkdir($root.'/app/modules/Billing', recursive: true);

    file_put_contents($root.'/src/app/Http/Controller.php', "<?php // one\n");
    file_put_contents($root.'/app/app/Http/Controller.php', "<?php // one\n");
    file_put_contents($root.'/src/modules/Billing/Query.php', "<?php // two\n");
    file_put_contents($root.'/app/modules/Billing/Query.php', "<?php // two\n");

    return $root;
}

it('reports nothing when the provisioned app matches the tracked overlay', function (): void {
    $root = driftTree('match');

    expect(fixture_drift_problems($root.'/src', $root.'/app'))->toBe([]);
});

it('names a tracked source the provisioned app never received', function (): void {
    $root = driftTree('missing');
    file_put_contents($root.'/src/app/Http/Added.php', "<?php // new\n");

    expect(fixture_drift_problems($root.'/src', $root.'/app'))->toBe(['missing:  app/Http/Added.php']);
});

it('names a tracked source the provisioned app holds an older copy of', function (): void {
    $root = driftTree('stale');
    file_put_contents($root.'/src/modules/Billing/Query.php', "<?php // two, edited\n");

    expect(fixture_drift_problems($root.'/src', $root.'/app'))->toBe(['differs:  modules/Billing/Query.php']);
});

it('ignores what the provisioned app carries beyond the overlay', function (): void {
    // A Laravel install is mostly files the overlay says nothing about — every one of them is fine.
    $root = driftTree('extra');
    file_put_contents($root.'/app/app/Http/Kernel.php', "<?php // skeleton\n");

    expect(fixture_drift_problems($root.'/src', $root.'/app'))->toBe([]);
});

it('is inert without a provisioned app', function (): void {
    // A contributor who never provisioned one gets the fixture group's own silent skip, not a failure.
    $root = sys_get_temp_dir().'/docuccino-drift-absent-'.uniqid();
    mkdir($root.'/tests/fixture-app/src', recursive: true);

    expect(fixture_drift_main($root))->toBe(0);
});

it('fails naming the drifted file and the remedy', function (): void {
    $root = sys_get_temp_dir().'/docuccino-drift-main-'.uniqid();
    mkdir($root.'/tests/fixture-app/src/app', recursive: true);
    mkdir($root.'/tests/fixture-app/app/app', recursive: true);
    mkdir($root.'/tests/fixture-app/app/vendor', recursive: true);
    file_put_contents($root.'/tests/fixture-app/app/vendor/autoload.php', "<?php\n");
    file_put_contents($root.'/tests/fixture-app/src/app/Late.php', "<?php // edited\n");

    $stderr = fopen('php://memory', 'r+');
    expect($stderr)->not->toBeFalse();

    $code = fixture_drift_main($root, $stderr);
    rewind($stderr);
    $report = (string) stream_get_contents($stderr);
    fclose($stderr);

    expect($code)->toBe(1)
        ->and($report)->toContain('missing:  app/Late.php')
        ->and($report)->toContain('steps 5-6 of')
        ->and($report)->toContain('tests/fixture-app/setup.md');
});
