<?php

declare(strict_types=1);

/**
 * Docuccino — Spike B  (the "Scramble-Pro-beater" proof)
 * ======================================================
 * Prove interprocedural constant-value recovery through a 2-deep user helper
 * chain — the exact case Scramble Pro fails on — plus pagination-terminal
 * detection behind a custom terminal.
 *
 * Reuses Spike A's embedding harness verbatim (ContainerFactory + generated neon
 * + Larastan bootstrap + the parser-priming trap) and layers a prototype
 * TraceVisitor / TypeScope / Tracer over the top to validate the plan's
 * `TypeEngine::trace()` boundary shape.
 *
 * Run:  php spikes/spike-b/run.php
 * (cwd-independent — chdir()s into the fixture app, per Spike A finding #3.)
 */

use Docuccino\SpikeB\ConstValue;
use Docuccino\SpikeB\QueryBuilderTraceVisitor;
use Docuccino\SpikeB\Tracer;
use Docuccino\SpikeB\TraceResult;
use PHPStan\Analyser\NodeScopeResolver;
use PHPStan\Analyser\ScopeFactory;
use PHPStan\DependencyInjection\ContainerFactory;
use PHPStan\File\FileHelper;
use PHPStan\Parser\Parser;
use PHPStan\Parser\PathRoutingParser;

$spikeDir = __DIR__;
$fixtureApp = dirname(__DIR__).'/fixture-app';

if (! is_dir($fixtureApp)) {
    fwrite(STDERR, "fixture-app not found at {$fixtureApp}\n");
    fwrite(STDERR, "Recreate it per spikes/fixture-app-setup.md and copy spike-b/fixture-src into it.\n");
    exit(1);
}

// 1. Autoload the fixture app (also registers PHPStan's PharAutoloader → unprefixed PHPStan\*).
require $fixtureApp.'/vendor/autoload.php';

// 2. Our prototype trace machinery.
require $spikeDir.'/src/ConstValue.php';
require $spikeDir.'/src/TypeScope.php';
require $spikeDir.'/src/TraceVisitor.php';
require $spikeDir.'/src/TraceResult.php';
require $spikeDir.'/src/CalleeResolver.php';
require $spikeDir.'/src/QueryBuilderTraceVisitor.php';
require $spikeDir.'/src/Tracer.php';

// 3. Generate the neon: Larastan's extension.neon (generics + reflection) + level/phpVersion.
//    No custom services needed for Spike B — we read literals, not synthesised return types.
$tmpDir = sys_get_temp_dir().'/docuccino-spike-b';
@mkdir($tmpDir, 0777, true);
$larastanNeon = $fixtureApp.'/vendor/larastan/larastan/extension.neon';

$neon = <<<NEON
includes:
    - {$larastanNeon}

parameters:
    level: 9
    paths: []
    tmpDir: {$tmpDir}
    phpVersion: 80500
NEON;

$generatedNeon = $tmpDir.'/docuccino.neon';
file_put_contents($generatedNeon, $neon);

echo "== Docuccino Spike B — QueryBuilder trace (Scramble-Pro-beater) ==\n";
echo "Fixture app: {$fixtureApp}\n\n";

$startWall = microtime(true);

// 4. Larastan boots the Laravel app from getcwd()/bootstrap/app.php (Spike A finding #3).
chdir($fixtureApp);

// 5. Build the PHPStan DI container.
$containerFactory = new ContainerFactory($fixtureApp);
$container = $containerFactory->create(
    $tmpDir,
    [$generatedNeon],
    [],
    [$fixtureApp],
);

// 5b. Execute neon-declared bootstrapFiles ourselves (Spike A finding #1) — otherwise
//     LARAVEL_VERSION stays undefined and analysis dies deep inside Larastan.
foreach ($container->getParameter('bootstrapFiles') as $bootstrapFile) {
    (static function (string $file): void {
        require_once $file;
    })($bootstrapFile);
}

/** @var NodeScopeResolver $nodeScopeResolver */
$nodeScopeResolver = $container->getByType(NodeScopeResolver::class);
/** @var ScopeFactory $scopeFactory */
$scopeFactory = $container->getByType(ScopeFactory::class);
/** @var Parser $parser */
$parser = $container->getService('defaultAnalysisParser');
/** @var FileHelper $fileHelper */
$fileHelper = $container->getByType(FileHelper::class);
/** @var PathRoutingParser $pathRoutingParser */
$pathRoutingParser = $container->getService('pathRoutingParser');

// 6. Wire the prototype trace and run it over the controller action.
$result = new TraceResult();
$terminals = ['paginate', 'simplePaginate', 'cursorPaginate', 'paginateList'];
$visitor = new QueryBuilderTraceVisitor($result, terminals: $terminals);

// Control (DOCUCCINO_NO_DESCENT=1): maxDepth 0 = Scramble-Pro behaviour (look only
// at the action body, never descend into the helper). Proves the allowedFilters
// literals are unrecoverable without interprocedural descent.
$maxDepth = getenv('DOCUCCINO_NO_DESCENT') === '1' ? 0 : 4;

$tracer = new Tracer(
    nodeScopeResolver: $nodeScopeResolver,
    scopeFactory: $scopeFactory,
    parser: $parser,
    fileHelper: $fileHelper,
    pathRoutingParser: $pathRoutingParser,
    visitor: $visitor,
    result: $result,
    maxDepth: $maxDepth,
    terminals: $terminals,
);

$action = 'App\\Http\\Controllers\\UserListController';
$tracer->trace($action, 'listUsers', 0, '(entry action)');

// 7. Report.
$rel = static fn (string $file): string => str_replace($fixtureApp.'/', '', $file);

echo "Entry: {$action}::listUsers()\n";
echo "Terminals configured: [".implode(', ', $terminals)."]\n\n";

echo "── allowedFilters recovered (".count($result->allowedFilters).") ──\n";
renderConstList($result->allowedFilters);

echo "\n── allowedSorts recovered (".count($result->allowedSorts).") ──\n";
renderConstList($result->allowedSorts);

echo "\n── defaultSort recovered (".count($result->defaultSort).") ──\n";
renderConstList($result->defaultSort);

echo "\n── pagination detection ──\n";
echo '  paginates: '.($result->paginates() ? 'YES' : 'no')."\n";
$perPage = $result->recoveredPerPage();
echo '  per_page recovered: '.($perPage !== null ? (string) $perPage : '<none>')."\n";
echo "  terminal hits (".count($result->terminalHits)."):\n";
foreach ($result->terminalHits as $i => $hit) {
    $loc = $hit['loc'];
    printf(
        "    #%d %s(perPage=%s) on %s  @ %s:%d\n",
        $i,
        $hit['terminal'],
        $hit['perPage'] !== null ? (string) $hit['perPage'] : 'unresolved',
        shortClass($hit['receiver']),
        $rel($loc['file']),
        $loc['line'],
    );
}

echo "\n── descent chain (depth accounting) ──\n";
$maxDepthSeen = 0;
foreach ($result->chain as $hop) {
    $maxDepthSeen = max($maxDepthSeen, $hop['depth']);
    $indent = str_repeat('    ', $hop['depth']);
    $via = $hop['via'] !== null ? "  [{$hop['via']}]" : '';
    $note = $hop['note'] !== null ? '  '.$hop['note'] : '';
    printf("  %s[%d] %s::%s%s%s\n", $indent, $hop['depth'], shortClass($hop['class']), $hop['method'], $via, $note);
}
echo "\n  max descent depth reached: {$maxDepthSeen} (>=2 hops required)\n";

$wall = microtime(true) - $startWall;
$peakMb = memory_get_peak_usage(true) / 1024 / 1024;

echo "\n── run stats ──\n";
echo sprintf("wall clock (container build + trace): %.2fs\n", $wall);
echo sprintf("peak memory (real): %.1f MB\n", $peakMb);

/**
 * @param list<ConstValue> $list
 */
function renderConstList(array $list): void
{
    if ($list === []) {
        echo "  (none)\n";

        return;
    }
    foreach ($list as $i => $cv) {
        printf("  [%d] %-11s %s\n", $i, $cv->kind, $cv->render());
    }
}

function shortClass(string $fqcn): string
{
    if (! str_contains($fqcn, '\\')) {
        return $fqcn;
    }
    $parts = explode('\\', $fqcn);

    return end($parts);
}
