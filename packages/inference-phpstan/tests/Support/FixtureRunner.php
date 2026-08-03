<?php

declare(strict_types=1);

namespace Docuccino\Inference\PhpStan\Tests\Support;

use Docuccino\Core\Inference\ActionAnalysis;
use Docuccino\Core\Inference\ClassMetadata;
use RuntimeException;

/**
 * Drives the {@see engine-runner.php} subprocess against `spikes/fixture-app/`
 * and decodes its JSON result. Keeps the fixture app's Laravel/Larastan out of
 * the Pest process (avoiding a symfony/console version clash) and mirrors how
 * the engine really runs — inside the host app's own process.
 */
final class FixtureRunner
{
    public static function appRoot(): string
    {
        return dirname(__DIR__, 4).'/spikes/fixture-app';
    }

    private static function runner(): string
    {
        return dirname(__DIR__).'/bin/engine-runner.php';
    }

    public static function available(): bool
    {
        $app = self::appRoot();

        return is_file($app.'/vendor/autoload.php')
            && is_file($app.'/vendor/larastan/larastan/extension.neon')
            && is_file($app.'/app/Http/Controllers/SpikeController.php')
            && is_file(self::runner());
    }

    public static function path(string $relative): string
    {
        return self::appRoot().'/'.ltrim($relative, '/');
    }

    /**
     * @return array<string, mixed>
     */
    public static function analyze(string $controllerRelPath, string $class, string $method): array
    {
        return self::invoke('analyze', self::path($controllerRelPath), $class, $method);
    }

    /**
     * @return array<string, mixed>
     */
    public static function traceQb(string $controllerRelPath, string $class, string $method): array
    {
        return self::invoke('trace-qb', self::path($controllerRelPath), $class, $method);
    }

    /**
     * Trace a controller with the REAL JsonApiPaginateTraceVisitor: returns whether it reached the
     * `jsonPaginate()` terminal and the folded per-call-site overrides (`maxResults`/`defaultSize`).
     *
     * @return array<string, mixed>
     */
    public static function traceJsonApiPaginate(string $controllerRelPath, string $class, string $method): array
    {
        return self::invoke('trace-json-api-paginate', self::path($controllerRelPath), $class, $method);
    }

    /**
     * The real engine's {@see ClassMetadata} for a class (its property
     * names + reflected types), serialized. The file argument is unused for this mode.
     *
     * @return array<string, mixed>
     */
    public static function classMetadata(string $class): array
    {
        return self::invoke('class-metadata', '', $class, '');
    }

    /**
     * The real engine's {@see ActionAnalysis} for a non-action callable —
     * an exception handler `render()` method (pass `$class`+`$method`, optionally a narrowing
     * `$param`+`$narrowType`) or a render-callback closure located by line (pass `$line`, empty
     * `$class`/`$method`). Serialized.
     *
     * @return array<string, mixed>
     */
    public static function analyzeCallable(
        string $relPath,
        string $class,
        string $method,
        int $line = 0,
        string $param = '',
        string $narrowType = '',
    ): array {
        return self::invoke('analyze-callable', self::path($relPath), $class, $method, (string) $line, $param, $narrowType);
    }

    /**
     * @return array<string, mixed>
     */
    private static function invoke(string $mode, string $file, string $class, string $method, string ...$extra): array
    {
        $command = implode(' ', array_map('escapeshellarg', [
            PHP_BINARY,
            self::runner(),
            $mode,
            $file,
            $class,
            $method,
            ...$extra,
        ])).' 2>/dev/null';

        $output = shell_exec($command);
        if (! is_string($output) || ! str_contains($output, '@@RESULT@@')) {
            throw new RuntimeException('engine-runner produced no result: '.var_export($output, true));
        }

        $json = substr($output, strpos($output, '@@RESULT@@') + strlen('@@RESULT@@'));
        /** @var array<string, mixed> $decoded */
        $decoded = json_decode(trim($json), true, flags: JSON_THROW_ON_ERROR);

        return $decoded;
    }
}
