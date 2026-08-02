<?php

declare(strict_types=1);

/*
 * Fixture-app engine runner (integration-test subprocess).
 *
 * The engine runs INSIDE the host Laravel app's process using the host app's
 * vendor — exactly how it will run in production, and how Phase 2b's workers will
 * run it. Booting it in-process from the root Pest run is not viable: Pest pulls
 * its own symfony/console, which collides with the fixture app's Laravel console
 * when both vendors are active. So the integration tests shell out to this
 * runner, which loads ONLY the fixture app's autoloader plus a hand-registered
 * PSR-4 map for the docuccino packages under test.
 *
 * Usage:
 *   php engine-runner.php analyze        <controllerFile> <class> <method>
 *   php engine-runner.php trace-qb       <controllerFile> <class> <method>
 *   php engine-runner.php class-metadata <ignored>        <class>
 *
 * Emits `@@RESULT@@` followed by a single JSON line (so any incidental host
 * output before it is ignored by the caller).
 */

use Docuccino\Core\Inference\ActionRef;
use Docuccino\Core\Inference\ClassRef;
use Docuccino\Inference\PhpStan\Analysis\EngineConfig;
use Docuccino\Inference\PhpStan\Analysis\PhpStanEngineFactory;
use Docuccino\Inference\PhpStan\Runtime\RuntimeConfig;
use Docuccino\Inference\PhpStan\Tests\Support\QueryBuilderProbe;

$repoRoot = dirname(__DIR__, 4);
$app = $repoRoot.'/spikes/fixture-app';

require $app.'/vendor/autoload.php';

// Hand-registered PSR-4 for the packages under test — no root composer vendor is
// loaded here, so the only phpstan/php-parser in play is the fixture app's.
spl_autoload_register(static function (string $class) use ($repoRoot): void {
    $map = [
        'Docuccino\\Core\\' => $repoRoot.'/packages/core/src/',
        'Docuccino\\Inference\\PhpStan\\Tests\\' => $repoRoot.'/packages/inference-phpstan/tests/',
        'Docuccino\\Inference\\PhpStan\\' => $repoRoot.'/packages/inference-phpstan/src/',
    ];
    foreach ($map as $prefix => $dir) {
        if (str_starts_with($class, $prefix)) {
            $relative = substr($class, strlen($prefix));
            $file = $dir.str_replace('\\', '/', $relative).'.php';
            if (is_file($file)) {
                require $file;
            }

            return;
        }
    }
});

$mode = $argv[1] ?? '';
$file = $argv[2] ?? '';
$class = $argv[3] ?? '';
$method = $argv[4] ?? '';

$tmp = sys_get_temp_dir().'/docuccino-runner-'.getmypid();
@mkdir($tmp, 0777, true);

$engine = (new PhpStanEngineFactory)->create(
    new RuntimeConfig($app, $tmp, PHP_VERSION_ID, [$app.'/app']),
    EngineConfig::forProject($app.'/app'),
);

$ref = new ActionRef($file, $class === '' ? null : $class, $method);

$result = match ($mode) {
    'analyze' => $engine->analyzeAction($ref)->toArray(),
    'class-metadata' => $engine->classMetadata(new ClassRef($class))->toArray(),
    'trace-qb' => (static function () use ($engine, $ref): array {
        $probe = new QueryBuilderProbe;
        $engine->trace($ref, $probe);

        return [
            'filters' => $probe->allowedFilters,
            'sorts' => $probe->allowedSorts,
            'default' => $probe->defaultSort,
            'terminals' => $probe->terminals,
            'paginates' => $probe->paginates(),
            'perPage' => $probe->recoveredPerPage(),
            'outermost' => $probe->outermostTerminal()['terminal'] ?? null,
        ];
    })(),
    default => ['error' => 'unknown mode: '.$mode],
};

fwrite(STDOUT, "\n@@RESULT@@".json_encode($result, JSON_THROW_ON_ERROR)."\n");
