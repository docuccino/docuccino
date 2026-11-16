<?php

declare(strict_types=1);

use Docuccino\Core\Pipeline\BuildWorkers;

/*
 * `php artisan docuccino:export` runs under the ceiling it asked for — `engine.memory_limit` or
 * `--memory-limit` — whatever `APP_ENV` says, and so does every worker it forks. Laravel's console kernel
 * reports no command to `CommandStarting` while the environment is `testing`, so a run that relied on that
 * event stayed at PHP's default there, alone and with no notice to explain dying of it.
 *
 * Each run is a process of its own, started at 128M through the console kernel's `handle()`, which is what
 * the artisan binary calls. Inference is off: the ceiling is settled when the command resolves its engine,
 * before any mode is consulted, so analysing nothing still answers for it.
 */
it('runs a command-line export, and its workers, under the ceiling it asked for', function (string $env, string $yaml, array $flags): void {
    $base = temporaryDirectory('console-ceiling');
    mkdir($base.'/bootstrap/cache', 0700, true);
    file_put_contents($base.'/docuccino.yaml', "engine:\n  mode: 'null'\n  workers: 2\n".$yaml);

    try {
        $run = runPhp(<<<'PHP'
            [, $base, $env] = $argv;
            putenv('APP_ENV='.$env);
            $_ENV['APP_ENV'] = $_SERVER['APP_ENV'] = $env;

            $app = Orchestra\Testbench\Foundation\Application::create($base, options: ['extra' => [
                'providers' => [Docuccino\Laravel\DocuccinoServiceProvider::class],
                'dont-discover' => ['*'],
            ]]);

            $exit = $app->make(Illuminate\Contracts\Console\Kernel::class)->handle(
                new Symfony\Component\Console\Input\ArgvInput(['artisan', 'docuccino:export', ...array_slice($argv, 3)]),
                new Symfony\Component\Console\Output\BufferedOutput,
            );

            $workers = $app->make(Docuccino\Core\Pipeline\BuildWorkers::class);
            $answered = $workers->answered();
            $worker = $workers->later(static fn (): string => (string) ini_get('memory_limit'))();

            // On a line of its own: the run's output carries whatever PHP printed beside it.
            echo "\nceiling: ".json_encode([
                'exit' => $exit,
                'unitTests' => $app->runningUnitTests(),
                'build' => ini_get('memory_limit'),
                'worker' => $worker,
                'forked' => $workers->answered() > $answered,
            ]);
            PHP, [$base, $env, ...$flags], ini: ['memory_limit' => '128M', 'grpc.enable_fork_support' => '1'], timeout: 60.0);

        preg_match('/^ceiling: (.+)$/m', $run['output'], $line);

        expect(json_decode($line[1] ?? 'null', true))->toBe([
            'exit' => 0,
            'unitTests' => $env === 'testing',
            'build' => '3G',
            'worker' => '3G',
            'forked' => true,
        ], $run['output']);
    } finally {
        removeTemporaryDirectory($base);
    }
})->with([
    'testing' => ['testing'],
    'production' => ['production'],
])->with([
    'engine.memory_limit' => ["  memory_limit: '3G'\n", []],
    '--memory-limit' => ['', ['--memory-limit=3G']],
])->skip(fn (): bool => ! BuildWorkers::forkable(), 'Forking needs the pcntl and posix extensions.');
