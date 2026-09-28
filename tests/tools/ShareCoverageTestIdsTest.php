<?php

declare(strict_types=1);

use Docuccino\Tools\PhpUnit\ShareCoverageTestIds;
use SebastianBergmann\CodeCoverage\Data\ProcessedCodeCoverageData;

/*
 * The coverage gate's parallel merge holds every worker's coverage at once, and a worker writes it with
 * serialize(), which spells a string out in full at every occurrence. One test id recorded against a
 * thousand lines is one string in the worker and a thousand in the parent, so the merge outgrew an 8G
 * limit with a few thousand distinct ids. Sharing is only acceptable if it changes the SIZE and nothing
 * the reports read, which is what these check — through the same serialize round trip and the same
 * merge the parent runs.
 */

it('spells each test id out once in the serialized coverage, where plain storage spells it at every line', function () {
    $build = function (): ProcessedCodeCoverageData {
        $data = new ProcessedCodeCoverageData;
        $data->setLineCoverage([
            '/src/A.php' => [1 => ['T::a', 'T::b'], 2 => ['T::a'], 3 => null, 4 => []],
            '/src/B.php' => [7 => ['T::b', 'T::a'], 8 => ['T::a']],
        ]);

        return $data;
    };

    $plain = serialize($build());
    $data = $build();
    ShareCoverageTestIds::share($data);
    $shared = serialize($data);

    // The premise: without sharing, the id is written once per line that recorded it.
    expect(substr_count($plain, '"T::a"'))->toBe(4)
        ->and(substr_count($shared, '"T::a"'))->toBe(1)
        ->and(substr_count($shared, '"T::b"'))->toBe(1);
});

it('reads back exactly the ids, the order and the empty and non-executable lines it was given', function () {
    $coverage = [
        '/src/A.php' => [1 => ['T::a', 'T::b'], 2 => ['T::a'], 3 => null, 4 => []],
        // A numeric-looking id becomes an integer KEY of the shared table; the value must stay a string.
        '/src/B.php' => [7 => ['123', 'T::a'], 8 => ['123']],
    ];
    $data = new ProcessedCodeCoverageData;
    $data->setLineCoverage($coverage);

    ShareCoverageTestIds::share($data);
    $read = unserialize(serialize($data));

    expect($read)->toBeInstanceOf(ProcessedCodeCoverageData::class)
        ->and($read->lineCoverage())->toBe($coverage);
});

it('merges shared coverage from separate workers into what plain coverage merges into', function () {
    $worker = function (array $coverage, bool $share): ProcessedCodeCoverageData {
        $data = new ProcessedCodeCoverageData;
        $data->setLineCoverage($coverage);

        if ($share) {
            ShareCoverageTestIds::share($data);
        }

        $read = unserialize(serialize($data));
        assert($read instanceof ProcessedCodeCoverageData);

        return $read;
    };
    $one = ['/src/A.php' => [1 => ['T::a'], 2 => [], 3 => null]];
    $two = ['/src/A.php' => [1 => ['U::a', 'T::a'], 2 => ['U::a'], 3 => null], '/src/B.php' => [5 => ['U::a']]];

    $merge = function (bool $share) use ($worker, $one, $two): array {
        $into = $worker($one, $share);
        $into->merge($worker($two, $share));

        return $into->lineCoverage();
    };

    expect($merge(true))->toBe($merge(false))
        ->and($merge(true)['/src/A.php'][1])->toBe(['T::a', 'U::a']);
});
