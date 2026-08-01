<?php

declare(strict_types=1);

/**
 * Docuccino — Spike A
 * ===================
 * Prove that PHPStan (distributed phar) + Larastan can be embedded
 * programmatically, in-process, to answer: "for a given Laravel controller
 * method, what are the types of every return path?"
 *
 * This is the Rector-style embedding: build PHPStan's DI container via
 * ContainerFactory with a generated neon, then drive
 * NodeScopeResolver::processNodes() over a single parsed file and harvest the
 * virtual PHPStan\Node\MethodReturnStatementsNode.
 *
 * Run:  php spikes/spike-a/run.php
 * (cwd-independent — the script chdir()s into the fixture app itself because
 *  Larastan's bootstrap boots the Laravel app from getcwd()/bootstrap/app.php.)
 */

use PHPStan\Analyser\NodeScopeResolver;
use PHPStan\Analyser\Scope;
use PHPStan\Analyser\ScopeContext;
use PHPStan\Analyser\ScopeFactory;
use PHPStan\DependencyInjection\ContainerFactory;
use PHPStan\Node\MethodReturnStatementsNode;
use PHPStan\Type\VerbosityLevel;
use PhpParser\Node;

$spikeDir = __DIR__;
$fixtureApp = dirname(__DIR__) . '/fixture-app';
$controllerFile = $fixtureApp . '/app/Http/Controllers/SpikeController.php';

if (! is_dir($fixtureApp)) {
    fwrite(STDERR, "fixture-app not found at {$fixtureApp}\n");
    exit(1);
}

// 1. Autoload the fixture app. This also registers PHPStan's PharAutoloader
//    (phpstan/phpstan ships bootstrap.php as a composer autoload "files" entry),
//    exposing unprefixed PHPStan\* classes from inside the phar.
require $fixtureApp . '/vendor/autoload.php';

// 2. Our bundled PHPStan extension (the JsonResponse payload-shape sub-proof).
//    Require it so Nette DI can instantiate it by class name from the neon.
require $spikeDir . '/src/JsonResponseFactoryExtension.php';

// 3. Generate the neon config: pull in Larastan's extension.neon, register our
//    dynamic-return-type extension, wire the JsonResponse<TPayload> stub, set
//    phpVersion / level / empty paths / temp dir.
$tmpDir = sys_get_temp_dir() . '/docuccino-spike-a';
@mkdir($tmpDir, 0777, true);

$larastanNeon = $fixtureApp . '/vendor/larastan/larastan/extension.neon';
$stubFile = $spikeDir . '/stubs/JsonResponse.stub';
$extensionClass = \Docuccino\SpikeA\JsonResponseFactoryExtension::class;

// Set DOCUCCINO_NO_STUB=1 to build WITHOUT our JsonResponse extension/stub —
// the control that shows response()->json([...]) collapses to a bare
// JsonResponse and the payload shape is lost (pass-criterion b, "before").
$withStub = getenv('DOCUCCINO_NO_STUB') !== '1';

$stubSection = $withStub
    ? "    stubFiles:\n        - {$stubFile}\n"
    : '';
$servicesSection = $withStub
    ? "services:\n    -\n        class: {$extensionClass}\n        tags:\n            - phpstan.broker.dynamicMethodReturnTypeExtension\n"
    : '';

$neon = <<<NEON
includes:
    - {$larastanNeon}

parameters:
    level: 9
    paths: []
    tmpDir: {$tmpDir}
    phpVersion: 80500
{$stubSection}
{$servicesSection}
NEON;

$generatedNeon = $tmpDir . '/docuccino.neon';
file_put_contents($generatedNeon, $neon);

echo "== Docuccino Spike A ==\n";
echo "Generated neon: {$generatedNeon}\n";
echo "Analysing: {$controllerFile}\n\n";

$startWall = microtime(true);

// 4. Larastan's bootstrap boots the Laravel app from getcwd()/bootstrap/app.php,
//    so we must run from the fixture app's directory.
chdir($fixtureApp);

// 5. Build the container. postInitializeContainer() (called at the tail of
//    create()) registers the PhpVersion / ReflectionProvider static accessors,
//    so the scope machinery is ready the moment create() returns.
$containerFactory = new ContainerFactory($fixtureApp);
$container = $containerFactory->create(
    $tmpDir,
    [$generatedNeon],   // additionalConfigFiles
    [],                 // analysedPaths (empty — we drive files by hand)
    [$fixtureApp],      // composerAutoloaderProjectPaths — lets PHPStan reflect App\*
);

// 5b. Execute the neon-declared bootstrapFiles ourselves. The PHPStan CLI runs
//     these via CommandHelper; a raw ContainerFactory embed does not, so Larastan
//     never boots the Laravel app (and LARAVEL_VERSION stays undefined) unless we
//     do it here. This is the "Larastan bootstrap boots the app" step.
$bootstrapFiles = $container->getParameter('bootstrapFiles');
foreach ($bootstrapFiles as $bootstrapFile) {
    (static function (string $file): void {
        require_once $file;
    })($bootstrapFile);
}

/** @var NodeScopeResolver $nodeScopeResolver */
$nodeScopeResolver = $container->getByType(NodeScopeResolver::class);
/** @var ScopeFactory $scopeFactory */
$scopeFactory = $container->getByType(ScopeFactory::class);
/** @var \PHPStan\Parser\Parser $parser */
$parser = $container->getService('defaultAnalysisParser');

// Normalise the path exactly the way PHPStan's parser router does, then register
// it as an analysed file in BOTH the resolver and the parser router.
/** @var \PHPStan\File\FileHelper $fileHelper */
$fileHelper = $container->getByType(\PHPStan\File\FileHelper::class);
$normalisedFile = $fileHelper->normalizePath($controllerFile);

// CRITICAL: defaultAnalysisParser -> CachedParser -> PathRoutingParser. The router
// only gives a file the *rich* (body-preserving) parse when it is in its analysed
// set; everything else goes through CleaningParser, which STRIPS method bodies —
// leaving MethodReturnStatementsNode with zero return statements. So the analysed
// set must be primed on the PathRoutingParser too, not just the resolver.
/** @var \PHPStan\Parser\PathRoutingParser $pathRoutingParser */
$pathRoutingParser = $container->getService('pathRoutingParser');
$pathRoutingParser->setAnalysedFiles([$normalisedFile]);

// Tell the resolver which files are "ours" (affects dependency tracking / descent).
$nodeScopeResolver->setAnalysedFiles([$normalisedFile]);

// 6. Parse + drive. The callback fires for every PHPStan virtual node; we watch
//    for MethodReturnStatementsNode, which pairs each `return` with its
//    flow-refined scope and carries the method's throw points.
$nodes = $parser->parseFile($controllerFile);
$scope = $scopeFactory->create(ScopeContext::create($controllerFile));

$precise = VerbosityLevel::precise();

$callback = function (Node $node, Scope $nodeScope) use ($precise): void {
    if (! $node instanceof MethodReturnStatementsNode) {
        return;
    }

    $method = $node->getMethodName();
    echo "── method {$method}() ──\n";

    $returnStatements = $node->getReturnStatements();
    if ($returnStatements === []) {
        echo "  (no return statements)\n";
    }

    foreach ($returnStatements as $i => $returnStatement) {
        $returnNode = $returnStatement->getReturnNode();
        $returnScope = $returnStatement->getScope();
        $line = $returnNode->getStartLine();
        $expr = $returnNode->expr;

        if ($expr === null) {
            echo "  return #{$i} @L{$line}: (void return)\n";
            continue;
        }

        $type = $returnScope->getType($expr);
        echo "  return #{$i} @L{$line}: " . $type->describe($precise) . "\n";
    }

    // Throw points harvested from the statement result (escaping exceptions;
    // caught ones already subtracted; @throws consulted).
    $throwPoints = $node->getStatementResult()->getThrowPoints();
    echo "  throw points: " . count($throwPoints) . "\n";
    foreach ($throwPoints as $j => $throwPoint) {
        $tpNode = $throwPoint->getNode();
        $tpLine = method_exists($tpNode, 'getStartLine') ? $tpNode->getStartLine() : -1;
        $canAny = $throwPoint->canContainAnyThrowable() ? 'yes' : 'no';
        $explicit = $throwPoint->isExplicit() ? 'explicit' : 'implicit';
        echo "    throw #{$j} @L{$tpLine}: "
            . $throwPoint->getType()->describe($precise)
            . " ({$explicit}, canContainAnyThrowable={$canAny})\n";
    }

    echo "\n";
};

$nodeScopeResolver->processNodes($nodes, $scope, $callback);

$wall = microtime(true) - $startWall;
$peakMb = memory_get_peak_usage(true) / 1024 / 1024;

echo "── run stats ──\n";
echo sprintf("wall clock (container build + analyse): %.2fs\n", $wall);
echo sprintf("peak memory (real): %.1f MB\n", $peakMb);
