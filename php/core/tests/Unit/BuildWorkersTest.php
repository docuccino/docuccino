<?php

declare(strict_types=1);

use Docuccino\Core\Pipeline\BuildWorkers;

/*
 * Workers are forked copies of the build, so what one did is only visible through what it left behind:
 * each job here writes a file naming the process that built it. A job built twice would find its file
 * there already, and one never built leaves none.
 *
 * A worker that has to die of a FATAL error does it in a script of its own ({@see runPhp()}): what a
 * worker inherits includes the shutdown work of the process it was forked from, and this one's belongs
 * to the test runner.
 */

beforeEach(function (): void {
    // Made before anything can skip, because the sweep below runs after a skipped test as well.
    $this->out = temporaryDirectory('workers-test');

    if (! BuildWorkers::forkable()) {
        $this->markTestSkipped('Forking needs the pcntl and posix extensions.');
    }
});

afterEach(function (): void {
    removeTemporaryDirectory($this->out ?? null);
});

/**
 * Units of `$sizes` jobs each, named `unit.job`.
 *
 * @param  list<int>  $sizes
 * @return list<list<string>>
 */
function workerUnits(array $sizes): array
{
    $units = [];
    foreach ($sizes as $unit => $size) {
        $units[] = array_map(static fn (int $job): string => $unit.'.'.$job, range(0, $size - 1));
    }

    return $units;
}

/** A job that records which process built it, refusing to be built twice. */
function recordingBuild(string $out): Closure
{
    return static function (string $job) use ($out): void {
        $handle = fopen($out.'/job-'.$job, 'x');
        if ($handle === false) {
            file_put_contents($out.'/twice-'.$job, '');

            return;
        }

        fwrite($handle, (string) getmypid());
        fclose($handle);
    };
}

/** @return array<string, string> job => the process that built it */
function builtBy(string $out): array
{
    $built = [];
    foreach (glob($out.'/job-*') ?: [] as $file) {
        $built[substr(basename($file), 4)] = (string) file_get_contents($file);
    }
    ksort($built);

    return $built;
}

it('builds every job exactly once, a unit in one worker, and none in the build itself', function (): void {
    $units = workerUnits([5, 3, 3, 2, 1, 1]);

    (new BuildWorkers(static fn (): int => 3))->run(3, $units, recordingBuild($this->out));

    $built = builtBy($this->out);
    $jobs = array_merge(...$units);
    sort($jobs);

    expect(array_keys($built))->toBe($jobs)
        ->and(glob($this->out.'/twice-*'))->toBe([])
        ->and($built)->not->toContain((string) getmypid());

    foreach ($units as $unit) {
        expect(array_unique(array_map(static fn (string $job): string => $built[$job], $unit)))->toHaveCount(1);
    }
});

it('runs the host hooks once before forking, and once in each worker', function (): void {
    $parent = (string) getmypid();
    $before = [];

    $workers = new BuildWorkers(
        static fn (): int => 3,
        beforeFork: static function () use (&$before): void {
            $before[] = (string) getmypid();
        },
        inWorker: fn () => file_put_contents($this->out.'/worker-'.getmypid(), ''),
    );
    $workers->run(3, workerUnits([1, 1, 1, 1, 1, 1]), recordingBuild($this->out));

    $inWorkers = array_map(static fn (string $file): string => substr(basename($file), 7), glob($this->out.'/worker-*') ?: []);

    expect($before)->toBe([$parent])
        ->and($inWorkers)->toHaveCount(3)
        ->and($inWorkers)->not->toContain($parent);
});

it('leaves the rest of the work to the others when a worker dies before it claims any', function (): void {
    $first = $this->out.'/first';

    $workers = new BuildWorkers(static fn (): int => 3, inWorker: static function () use ($first): void {
        $claim = @fopen($first, 'x');
        if ($claim !== false) {
            fclose($claim);
            posix_kill(posix_getpid(), SIGKILL);
        }
    });
    $workers->run(3, workerUnits([2, 2, 2, 2]), recordingBuild($this->out));

    expect(builtBy($this->out))->toHaveCount(8);
});

it('stops a worker at a job that throws, and the others still build every other unit', function (): void {
    $build = recordingBuild($this->out);
    $units = workerUnits([2, 2, 2, 2]);

    (new BuildWorkers(static fn (): int => 2))->run(2, $units, static function (string $job) use ($build): void {
        if ($job === '0.0') {
            throw new RuntimeException('a route that would not build');
        }

        $build($job);
    });

    // The unit that threw stays unbuilt — the build it belongs to makes it itself.
    expect(array_keys(builtBy($this->out)))->toBe(['1.0', '1.1', '2.0', '2.1', '3.0', '3.1']);
});

it('removes its claims once every worker has exited', function (): void {
    // This process's own claims: a suite running in parallel has other runs making theirs meanwhile.
    $claims = static fn (): array => glob(sys_get_temp_dir().'/docuccino-claims-'.getmypid().'-*') ?: [];
    $before = $claims();

    (new BuildWorkers(static fn (): int => 2))->run(2, workerUnits([1, 1]), recordingBuild($this->out));

    // Workers really ran, so there were claims to remove — a run that forked nothing would pass the last line too.
    expect(builtBy($this->out))->toHaveCount(2)
        ->and(builtBy($this->out))->not->toContain((string) getmypid())
        ->and($claims())->toBe($before);
});

it('builds a unit only for the first drain to claim it, however many drain the same claims', function (): void {
    // The claim loop a worker runs, driven here in one process through reflection: a worker is a fork, and
    // what a fork executes is invisible to the run that measures it. Two drains over one set of claims are
    // two workers, one after the other, both forked from a build that is still there.
    $drain = new ReflectionMethod(BuildWorkers::class, 'drain');
    $claims = $this->out.'/claims';
    mkdir($claims);
    $built = [];
    $build = static function (string $job) use (&$built): void {
        $built[] = $job;
    };

    $drain->invoke(null, posix_getppid(), $claims, workerUnits([2, 1]), $build);
    $drain->invoke(null, posix_getppid(), $claims, workerUnits([2, 1]), $build);

    expect($built)->toBe(['0.0', '0.1', '1.0']);
});

it('stops a worker whose build is gone, and takes away what that build would have', function (): void {
    // A worker's whole life short of its end, in this process: the build it names as its parent is not this
    // process's parent, which is what a worker sees once the build it was forked from has been killed.
    $serve = new ReflectionMethod(BuildWorkers::class, 'serve');
    $claims = $this->out.'/claims';
    $scratch = $this->out.'/scratch';
    mkdir($claims);
    mkdir($scratch);
    file_put_contents($scratch.'/.gitignore', '*');
    $built = [];

    $serve->invoke(null, posix_getppid() + 1, $claims, $scratch, workerUnits([2, 1]), static function (string $job) use (&$built): void {
        $built[] = $job;
    }, null);

    expect($built)->toBe([])
        ->and(is_dir($claims))->toBeFalse()
        ->and(is_dir($scratch))->toBeFalse();
});

it('keeps a worker to its drain while its build is there, whatever the host hook throws', function (): void {
    $serve = new ReflectionMethod(BuildWorkers::class, 'serve');
    $claims = $this->out.'/claims';
    mkdir($claims);
    $built = [];
    $build = static function (string $job) use (&$built): void {
        $built[] = $job;
    };

    $serve->invoke(null, posix_getppid(), $claims, null, workerUnits([1, 1]), $build, null);
    $serve->invoke(null, posix_getppid(), $claims, null, workerUnits([1, 1]), $build, static fn () => throw new RuntimeException('a hook that failed'));

    // The first built both units, and the second threw before claiming anything; neither took the claims away.
    expect($built)->toBe(['0.0', '1.0'])
        ->and(is_dir($claims))->toBeTrue();
});

it('stops every worker soon after its build is killed, and leaves nothing of that build behind', function (): void {
    $build = null;
    $jobs = 60;

    $run = runPhp(<<<'PHP'
        [$out, $jobs] = array_slice($argv, 1);
        $scratch = $out.'/scratch';
        mkdir($scratch);
        file_put_contents($scratch.'/.gitignore', '*');

        $units = array_map(static fn (int $job): array => [(string) $job], range(0, (int) $jobs - 1));

        (new Docuccino\Core\Pipeline\BuildWorkers(static fn (): int => 2))->run(2, $units, static function (string $job) use ($out): void {
            usleep(100000);
            file_put_contents($out.'/job-'.$job, '');
        }, $scratch);
        PHP, [$this->out, (string) $jobs], timeout: 20.0, meanwhile: function (int $pid) use (&$build): bool {
        if (count(glob($this->out.'/job-*') ?: []) < 2) {
            return false;
        }

        // The build is killed the way a timeout kills it: no chance to run anything of its own on the way out.
        $build = $pid;
        posix_kill($pid, SIGKILL);

        return true;
    }, settle: 10.0);

    expect($build)->not->toBeNull()
        // Every worker ended by itself, well before the work the build was killed in the middle of was done.
        ->and($run['settled'])->toBeTrue()
        ->and(count(glob($this->out.'/job-*') ?: []))->toBeLessThan($jobs)
        ->and(glob(sys_get_temp_dir().'/docuccino-claims-'.$build.'-*'))->toBe([])
        ->and(is_dir($this->out.'/scratch'))->toBeFalse();
});

it('ends a worker that dies of a fatal error right after the shutdown work it inherited, saying nothing', function (): void {
    // grpc is loaded here without fork support wherever it is installed, and a forked child that reaches
    // module shutdown under it never ends — so the timeout is what this fails by on a machine that has it.
    $run = runPhp(<<<'PHP'
        [$out] = array_slice($argv, 1);
        ini_set('log_errors', '1');
        ini_set('error_log', $out.'/error.log');
        ini_set('display_errors', 'stderr');

        // The host's own shutdown work, which every worker inherits and nothing can stop a dying one running.
        register_shutdown_function(static function () use ($out): void {
            file_put_contents($out.'/host-'.getmypid(), '');
        });

        (new Docuccino\Core\Pipeline\BuildWorkers(static fn (): int => 2))->run(2, [['fatal'], ['a'], ['b'], ['c']], static function (string $job) use ($out): void {
            if ($job === 'fatal') {
                register_shutdown_function(static function () use ($out): void {
                    file_put_contents($out.'/after-'.getmypid(), '');
                });

                ini_set('memory_limit', (string) (memory_get_usage() + 4 * 1024 * 1024));
                $hog = [];
                while (true) {
                    $hog[] = str_repeat('x', 65536);
                }
            }

            file_put_contents($out.'/job-'.$job, '');
        });

        echo 'returned';
        PHP, [$this->out], timeout: 20.0);

    expect($run['exit'])->toBe(0, $run['output'])
        ->and($run['output'])->toBe('returned')
        ->and(array_keys(builtBy($this->out)))->toBe(['a', 'b', 'c'])
        // The host's shutdown work ran in the build and in the worker that died, and nothing registered
        // after the worker's own end did.
        ->and(glob($this->out.'/host-*'))->toHaveCount(2)
        ->and(glob($this->out.'/after-*'))->toBe([])
        ->and(is_file($this->out.'/error.log'))->toBeFalse();
});

it('builds alone when this machine refuses a fork, under a host that turns every warning into an exception', function (): void {
    $run = runPhp(<<<'PHP'
        // One process for this user, set here rather than by a shell, since `sh` is dash on some systems and
        // its `ulimit` has no -u.
        posix_setrlimit(POSIX_RLIMIT_NPROC, 1, 1);

        // As Laravel's handler does: a warning the code has not silenced is an ErrorException.
        set_error_handler(static function (int $level, string $message, string $file = '', int $line = 0): bool {
            if ((error_reporting() & $level) !== 0) {
                throw new ErrorException($message, 0, $level, $file, $line);
            }

            return false;
        });

        $probe = @pcntl_fork();
        if ($probe === 0) {
            posix_kill(posix_getpid(), SIGKILL);
        }
        if ($probe !== -1) {
            pcntl_waitpid($probe, $status);
            echo 'unlimited';

            exit(0);
        }

        (new Docuccino\Core\Pipeline\BuildWorkers(static fn (): int => 2))->run(2, [['a'], ['b']], static fn (string $job) => null);

        echo 'returned';
        PHP, timeout: 20.0);

    if ($run['output'] === 'unlimited') {
        $this->markTestSkipped('This process may fork past a process limit (running as root, most likely).');
    }

    expect($run['exit'])->toBe(0, $run['output'])
        ->and($run['output'])->toBe('returned');
});

it('builds alone when the host cannot settle the process for a fork', function (): void {
    $workers = new BuildWorkers(
        static fn (): int => 2,
        beforeFork: static fn () => throw new RuntimeException('a connection that would not close'),
        inWorker: fn () => file_put_contents($this->out.'/worker-'.getmypid(), ''),
    );

    $workers->run(2, workerUnits([1, 1]), recordingBuild($this->out));

    expect(builtBy($this->out))->toBe([])
        ->and(glob($this->out.'/worker-*'))->toBe([])
        ->and(glob(sys_get_temp_dir().'/docuccino-claims-'.getmypid().'-*'))->toBe([]);
});

it('takes a directory of its own away quietly, however much of it is already gone', function (): void {
    $directory = BuildWorkers::directory('removal');
    expect($directory)->not->toBeNull();
    file_put_contents($directory.'/a', '');
    file_put_contents($directory.'/.gitignore', '*');

    // A warning here would be an exception under a host's handler — Laravel's throws for every one the code
    // has not silenced — and the build around it would die.
    set_error_handler(static function (int $level, string $message): bool {
        if ((error_reporting() & $level) !== 0) {
            throw new ErrorException($message, 0, $level);
        }

        return false;
    });

    try {
        BuildWorkers::remove($directory);
        BuildWorkers::remove($directory);
    } finally {
        restore_error_handler();
    }

    expect(file_exists($directory))->toBeFalse()
        ->and($directory)->toStartWith(sys_get_temp_dir().'/docuccino-removal-'.getmypid().'-');
});

it('asks for a worker per eight cold operations, within the limit, and none below two', function (int $operations, int $limit, int $expected): void {
    expect((new BuildWorkers(static fn (): int => $limit))->for($operations))->toBe($expected);
})->with([
    'nothing cold' => [0, 8, 1],
    'too few for two' => [15, 8, 1],
    'enough for two' => [16, 8, 2],
    'held to the limit' => [222, 8, 8],
    'a limit of one' => [222, 1, 1],
    'more than the limit allows' => [1000, 4, 4],
]);

it('may fork only where the limit allows a second process', function (int $limit, bool $expected): void {
    expect((new BuildWorkers(static fn (): int => $limit))->mayFork())->toBe($expected);
})->with([
    'none' => [0, false],
    'one' => [1, false],
    'two' => [2, true],
]);

it('never forks for a build that may use no workers', function (): void {
    expect(BuildWorkers::none()->for(1000))->toBe(1)
        ->and(BuildWorkers::none()->mayFork())->toBeFalse();
});

it('asks the host for its limit once, however often the build asks about workers', function (): void {
    // The host's limit can read the machine — a CPU count that shells out, a cgroup file — and a build asks
    // whether it may fork before it counts what is cold, then asks again with the count.
    $asked = 0;
    $workers = new BuildWorkers(static function () use (&$asked): int {
        $asked++;

        return 4;
    });

    $workers->mayFork();
    $workers->for(100);
    $workers->for(8);

    expect($asked)->toBe(1);
});
