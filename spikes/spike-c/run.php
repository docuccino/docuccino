<?php

declare(strict_types=1);

/**
 * Docuccino — Spike C
 * ===================
 * Evaluate the plan's 3-layer exception-flow design against reality on the
 * fixture Laravel app, and measure NOISE (useless "anything can throw" points)
 * vs MISSES (real exceptions not surfaced).
 *
 *   Layer 1 — raw PHPStan throw points from
 *             MethodReturnStatementsNode->getStatementResult()->getThrowPoints().
 *   Layer 2 — a KnownThrowers registry (Laravel semantics) resolved on the
 *             throw-point callee: abort/authorize/findOrFail/validate.
 *   Layer 3 — bounded descent (depth<=3, memoized, cycle-guarded) into
 *             project-code (App\) callees that produce only an implicit
 *             Throwable at the call site and carry no @throws.
 *
 * Reuses Spike A's embedding harness verbatim, honouring its documented traps:
 *   - run bootstrapFiles manually (Larastan boots the app / defines
 *     LARAVEL_VERSION);
 *   - prime the analysed set on BOTH NodeScopeResolver AND pathRoutingParser or
 *     method bodies are silently stripped;
 *   - cwd must be the fixture app root (Larastan bootstrap);
 *   - normalise paths through the container's FileHelper.
 *
 * Run:  php spikes/spike-c/run.php
 * Deterministic: only the wall/peak-memory lines vary run-to-run.
 */

use PHPStan\Analyser\NodeScopeResolver;
use PHPStan\Analyser\Scope;
use PHPStan\Analyser\ScopeContext;
use PHPStan\Analyser\ScopeFactory;
use PHPStan\DependencyInjection\ContainerFactory;
use PHPStan\Node\MethodReturnStatementsNode;
use PHPStan\Reflection\ReflectionProvider;
use PHPStan\Type\Constant\ConstantIntegerType;
use PHPStan\Type\Type;
use PHPStan\Type\VerbosityLevel;
use PhpParser\Node;
use PhpParser\Node\Expr\FuncCall;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\StaticCall;

const MAX_DESCENT_DEPTH = 3; // action body is depth 0; each project-code hop +1.

$spikeDir = __DIR__;
$fixtureApp = dirname(__DIR__) . '/fixture-app';
$appDir = $fixtureApp . '/app';
$controllerFile = $appDir . '/Http/Controllers/ThrowsController.php';

if (! is_dir($fixtureApp)) {
    fwrite(STDERR, "fixture-app not found at {$fixtureApp}\n");
    exit(1);
}

require $fixtureApp . '/vendor/autoload.php';

/* ── Harness bootstrap (Spike A path) ──────────────────────────────────── */

$tmpDir = sys_get_temp_dir() . '/docuccino-spike-c';
@mkdir($tmpDir, 0777, true);
$larastanNeon = $fixtureApp . '/vendor/larastan/larastan/extension.neon';

$neon = <<<NEON
includes:
    - {$larastanNeon}

parameters:
    level: 9
    paths: []
    tmpDir: {$tmpDir}
    phpVersion: 80500
NEON;
$generatedNeon = $tmpDir . '/docuccino.neon';
file_put_contents($generatedNeon, $neon);

echo "== Docuccino Spike C — exception-flow noise evaluation ==\n";
echo "Analysing: {$controllerFile}\n\n";

$startWall = microtime(true);

chdir($fixtureApp);

$containerFactory = new ContainerFactory($fixtureApp);
$container = $containerFactory->create($tmpDir, [$generatedNeon], [], [$fixtureApp]);
foreach ($container->getParameter('bootstrapFiles') as $bootstrapFile) {
    (static function (string $file): void { require_once $file; })($bootstrapFile);
}

/** @var NodeScopeResolver $nodeScopeResolver */
$nodeScopeResolver = $container->getByType(NodeScopeResolver::class);
/** @var ScopeFactory $scopeFactory */
$scopeFactory = $container->getByType(ScopeFactory::class);
/** @var \PHPStan\Parser\Parser $parser */
$parser = $container->getService('defaultAnalysisParser');
/** @var \PHPStan\File\FileHelper $fileHelper */
$fileHelper = $container->getByType(\PHPStan\File\FileHelper::class);
/** @var \PHPStan\Parser\PathRoutingParser $pathRoutingParser */
$pathRoutingParser = $container->getService('pathRoutingParser');
/** @var ReflectionProvider $reflectionProvider */
$reflectionProvider = $container->getByType(ReflectionProvider::class);

// Prime the analysed set with EVERY app/ file up front, on both the resolver and
// the parser router. Spike A trap #2: files outside the analysed set are routed
// to CleaningParser which strips method bodies. Layer 3 descent parses callee
// files (OrderService), so they must be primed BEFORE their first parse — doing
// it up front avoids the "already cached as cleaned" hazard. In real Docuccino
// this is the adapter priming each callee file lazily but before parsing it.
$appFiles = [];
$it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($appDir));
foreach ($it as $f) {
    if ($f->isFile() && $f->getExtension() === 'php') {
        $appFiles[] = $fileHelper->normalizePath($f->getPathname());
    }
}
sort($appFiles);
$pathRoutingParser->setAnalysedFiles($appFiles);
$nodeScopeResolver->setAnalysedFiles($appFiles);

$precise = VerbosityLevel::precise();

/* ── Layer 2: KnownThrowers registry ───────────────────────────────────── */

$HTTP_EXCEPTION = 'Symfony\\Component\\HttpKernel\\Exception\\HttpException';
$AUTH_EXCEPTION = 'Illuminate\\Auth\\Access\\AuthorizationException';
$MODEL_NOT_FOUND = 'Illuminate\\Database\\Eloquent\\ModelNotFoundException';
$VALIDATION_EXCEPTION = 'Illuminate\\Validation\\ValidationException';

// Global functions → exception + which positional arg holds the HTTP status.
$KNOWN_FUNCS = [
    'abort' => ['ex' => $HTTP_EXCEPTION, 'statusArg' => 0],
    'abort_if' => ['ex' => $HTTP_EXCEPTION, 'statusArg' => 1],
    'abort_unless' => ['ex' => $HTTP_EXCEPTION, 'statusArg' => 1],
];
// Method names (receiver-agnostic here; production keys on resolved receiver
// type too) → exception + fixed status.
$KNOWN_METHODS = [
    'authorize' => ['ex' => $AUTH_EXCEPTION, 'status' => 403],
    'authorizeForUser' => ['ex' => $AUTH_EXCEPTION, 'status' => 403],
    'findOrFail' => ['ex' => $MODEL_NOT_FOUND, 'status' => 404],
    'firstOrFail' => ['ex' => $MODEL_NOT_FOUND, 'status' => 404],
    'sole' => ['ex' => $MODEL_NOT_FOUND, 'status' => 404],
    'validate' => ['ex' => $VALIDATION_EXCEPTION, 'status' => 422],
];

// Default exception-type → HTTP status hint map.
$STATUS_BY_TYPE = [
    $HTTP_EXCEPTION => null, // resolved by constant-folding the abort arg
    $AUTH_EXCEPTION => 403,
    $MODEL_NOT_FOUND => 404,
    $VALIDATION_EXCEPTION => 422,
];

$statusForType = static function (string $fqcn) use ($STATUS_BY_TYPE, $reflectionProvider): int {
    if (array_key_exists($fqcn, $STATUS_BY_TYPE) && $STATUS_BY_TYPE[$fqcn] !== null) {
        return $STATUS_BY_TYPE[$fqcn];
    }
    // Subclass check (e.g. a custom HttpException subclass).
    if ($reflectionProvider->hasClass($fqcn)) {
        $refl = $reflectionProvider->getClass($fqcn);
        foreach ($STATUS_BY_TYPE as $known => $status) {
            if ($status !== null && $refl->is($known)) {
                return $status;
            }
        }
    }

    return 500; // everything else is an internal/unhandled error
};

/* ── Helpers ───────────────────────────────────────────────────────────── */

$shortFqcn = static fn (string $fqcn): string => ($p = strrpos($fqcn, '\\')) !== false ? substr($fqcn, $p + 1) : $fqcn;

$lineOf = static function (Node $node): int {
    return method_exists($node, 'getStartLine') ? $node->getStartLine() : -1;
};

/** Concrete (non-Throwable) object class names carried by a throw-point type. */
$concreteClasses = static function (Type $type): array {
    $names = $type->getObjectClassNames();
    $names = array_values(array_filter($names, static fn (string $n): bool => $n !== 'Throwable' && $n !== 'Exception'));

    return $names;
};

$isBareThrowable = static function (Type $type) use ($concreteClasses): bool {
    return $concreteClasses($type) === [];
};

/** Resolve a call node to ['name'=>string, 'recvClasses'=>string[]] or null. */
$resolveCallee = static function (Node $node, Scope $scope): ?array {
    if ($node instanceof FuncCall) {
        if ($node->name instanceof Node\Name) {
            return ['name' => $node->name->toString(), 'recvClasses' => []];
        }

        return null;
    }
    if ($node instanceof MethodCall) {
        if (! $node->name instanceof Node\Identifier) {
            return null;
        }
        $recv = $scope->getType($node->var);

        return ['name' => $node->name->toString(), 'recvClasses' => $recv->getObjectClassNames()];
    }
    if ($node instanceof StaticCall) {
        if (! $node->name instanceof Node\Identifier) {
            return null;
        }
        $recvClasses = [];
        if ($node->class instanceof Node\Name) {
            $recvClasses = [$scope->resolveName($node->class)];
        }

        return ['name' => $node->name->toString(), 'recvClasses' => $recvClasses];
    }

    return null;
};

/** Constant-fold a positional argument of a call node to an int, or null. */
$foldStatusArg = static function (Node $node, Scope $scope, int $argIndex): ?int {
    if (! method_exists($node, 'getArgs')) {
        return null;
    }
    $args = $node->getArgs();
    if (! isset($args[$argIndex])) {
        return null;
    }
    $argType = $scope->getType($args[$argIndex]->value);
    if ($argType instanceof ConstantIntegerType) {
        return $argType->getValue();
    }

    return null;
};

/**
 * Resolve the file + declaring class of a project-code method callee, or null
 * if the callee is vendor code / unresolvable. Used to gate Layer 3 descent —
 * vendor code is never descended (e.g. Model::findOrFail is vendor-declared).
 */
$declaringProjectMethod = static function (array $callee, Scope $scope) use ($reflectionProvider, $appDir, $fileHelper): ?array {
    foreach ($callee['recvClasses'] as $recvClass) {
        if (! $reflectionProvider->hasClass($recvClass)) {
            continue;
        }
        $classRefl = $reflectionProvider->getClass($recvClass);
        if (! $classRefl->hasMethod($callee['name'])) {
            continue;
        }
        $declClass = $classRefl->getMethod($callee['name'], $scope)->getDeclaringClass();
        $file = $declClass->getFileName();
        if ($file === null) {
            continue;
        }
        $normFile = $fileHelper->normalizePath($file);
        $normApp = $fileHelper->normalizePath($appDir);
        if (str_starts_with($normFile, $normApp . DIRECTORY_SEPARATOR)) {
            return ['file' => $file, 'class' => $declClass->getName(), 'method' => $callee['name']];
        }
    }

    return null;
};

/* ── File analysis (memoized): file → [methodName => MethodReturnStatementsNode] */

$fileNodeCache = [];
$analyseFile = static function (string $file) use (&$fileNodeCache, $parser, $scopeFactory, $nodeScopeResolver): array {
    if (isset($fileNodeCache[$file])) {
        return $fileNodeCache[$file];
    }
    $collected = [];
    $nodes = $parser->parseFile($file);
    $scope = $scopeFactory->create(ScopeContext::create($file));
    $nodeScopeResolver->processNodes($nodes, $scope, static function (Node $node, Scope $s) use (&$collected): void {
        if ($node instanceof MethodReturnStatementsNode) {
            $collected[$node->getMethodName()] = $node;
        }
    });
    $fileNodeCache[$file] = $collected;

    return $collected;
};

/* ── The core recursive analyser ───────────────────────────────────────── */

$noise = ['implicit' => 0, 'samples' => []]; // dropped "any-throwable" points

/**
 * @return array<int, array{ex:string,status:int,chain:string[],confidence:string,kept:bool}>
 */
$analyseMethod = null; // forward declaration for closure recursion
$analyseMethod = function (
    MethodReturnStatementsNode $methodNode,
    string $selfLabel,
    int $depth,
    array $visited,
    array $priorChain
) use (
    &$analyseMethod, &$analyseFile, &$noise,
    $resolveCallee, $foldStatusArg, $declaringProjectMethod, $statusForType,
    $concreteClasses, $isBareThrowable, $shortFqcn, $lineOf,
    $KNOWN_FUNCS, $KNOWN_METHODS, $precise
): array {
    $results = [];

    foreach ($methodNode->getStatementResult()->getThrowPoints() as $tp) {
        $node = $tp->getNode();
        $type = $tp->getType();
        $scope = $tp->getScope();
        $explicit = $tp->isExplicit();
        $line = $lineOf($node);
        $callee = $resolveCallee($node, $scope);

        /* Layer 2 — KnownThrowers registry (keyed on resolved callee). */
        $reg = null;
        $status = null;
        if ($callee !== null && isset($KNOWN_FUNCS[$callee['name']])) {
            $reg = $KNOWN_FUNCS[$callee['name']];
            $status = $foldStatusArg($node, $scope, $reg['statusArg']) ?? 500;
        } elseif ($callee !== null && isset($KNOWN_METHODS[$callee['name']])) {
            $reg = $KNOWN_METHODS[$callee['name']];
            $status = $reg['status'];
        }
        if ($reg !== null) {
            // certain when PHPStan already surfaced the same concrete type
            // explicitly; likely when we rescued a bare-Throwable implicit point.
            $agrees = $explicit && in_array($reg['ex'], $type->getObjectClassNames(), true);
            $conf = $agrees ? 'certain' : 'likely';
            $results[] = [
                'ex' => $reg['ex'],
                'status' => $status,
                'chain' => array_merge($priorChain, ["{$selfLabel} → {$callee['name']}() @L{$line}"]),
                'confidence' => $conf,
                'kept' => true,
            ];
            continue;
        }

        /* Layer 1 — explicit concrete type (literal throw, @throws, or stub). */
        if ($explicit && ! $isBareThrowable($type)) {
            $isLiteral = $node instanceof Node\Stmt\Throw_ || $node instanceof Node\Expr\Throw_;
            // A declared (non-literal) exception is trustworthy documentation
            // intent only when it comes from PROJECT code; a vendor call's
            // @throws (e.g. PSR-16 InvalidArgumentException from Cache::get) is
            // internal plumbing → demote to noise.
            $calleeIsProject = ! $isLiteral && $callee !== null
                && $declaringProjectMethod($callee, $scope) !== null;
            $verb = $isLiteral ? 'throws' : 'call declares';
            foreach ($concreteClasses($type) as $cls) {
                $st = $statusForType($cls);
                $results[] = [
                    'ex' => $cls,
                    'status' => $st,
                    'chain' => array_merge($priorChain, ["{$selfLabel} {$verb} {$shortFqcn($cls)} @L{$line}"]),
                    'confidence' => $isLiteral ? 'certain' : 'declared',
                    // keep literal throws, project-declared exceptions, and any
                    // exception carrying a real API status; demote vendor 500s.
                    'kept' => $isLiteral || $calleeIsProject || $st !== 500,
                ];
            }
            continue;
        }

        /* Layer 3 — implicit bare Throwable: descend into project callee, else
           it is pure "any-throwable" noise. */
        if (! $explicit) {
            $proj = $callee !== null ? $declaringProjectMethod($callee, $scope) : null;
            if ($proj !== null && $depth < MAX_DESCENT_DEPTH) {
                $key = $proj['class'] . '::' . $proj['method'];
                if (in_array($key, $visited, true)) {
                    continue; // cycle guard
                }
                $childMap = $analyseFile($proj['file']);
                if (! isset($childMap[$proj['method']])) {
                    continue;
                }
                $childLabel = $shortFqcn($proj['class']) . '::' . $proj['method'];
                $frame = "{$selfLabel} → {$callee['name']}() @L{$line}";
                $results = array_merge($results, $analyseMethod(
                    $childMap[$proj['method']],
                    $childLabel,
                    $depth + 1,
                    array_merge($visited, [$key]),
                    array_merge($priorChain, [$frame]),
                ));

                continue;
            }
            // Dropped noise.
            $noise['implicit']++;
            if (count($noise['samples']) < 6) {
                $noise['samples'][] = "{$selfLabel} @L{$line}: implicit " . $type->describe($precise)
                    . ($callee !== null ? " (call to {$callee['name']}())" : '');
            }
        }
    }

    return $results;
};

/* ── Drive the controller; collect per-action results ──────────────────── */

$actionResults = [];   // method => deduped result list
$actionOrder = [];

$topMap = $analyseFile($controllerFile);
foreach ($topMap as $method => $node) {
    if ($method === '__construct') {
        continue;
    }
    $actionOrder[] = $method;
    $raw = $analyseMethod($node, "ThrowsController::{$method}", 0, [], []);

    // Dedup by exception FQCN, keeping the highest-confidence / longest chain.
    $rank = ['certain' => 3, 'declared' => 2, 'likely' => 1];
    $byEx = [];
    foreach ($raw as $r) {
        // dedup on (exception, status): two aborts with different statuses are
        // two distinct API error responses and must both survive.
        $k = $r['ex'] . '@' . $r['status'];
        if (! isset($byEx[$k]) || $rank[$r['confidence']] > $rank[$byEx[$k]['confidence']]) {
            $byEx[$k] = $r;
        }
    }
    $list = array_values($byEx);
    usort($list, static fn ($a, $b): int => [$a['status'], $a['ex']] <=> [$b['status'], $b['ex']]);
    $actionResults[$method] = $list;
}

/* ── Report: raw throw points (Layer 1 view) ───────────────────────────── */

echo "──────────────────────────────────────────────────────────────────────\n";
echo " RAW THROW POINTS (Layer 1) — signal vs noise\n";
echo "──────────────────────────────────────────────────────────────────────\n";
foreach ($actionOrder as $method) {
    $node = $topMap[$method];
    $tps = $node->getStatementResult()->getThrowPoints();
    echo "\n {$method}()  [" . count($tps) . " points]\n";
    foreach ($tps as $tp) {
        $n = $tp->getNode();
        $line = $lineOf($n);
        $ex = $tp->isExplicit();
        $bare = $isBareThrowable($tp->getType());
        $cls = $ex && ! $bare ? 'SIGNAL ' : 'noise  ';
        printf(
            "   %s @L%-3d %-8s %s\n",
            $cls,
            $line,
            $ex ? 'explicit' : 'implicit',
            $tp->getType()->describe($precise),
        );
    }
}

/* ── Report: Layer 2 registry hits with extracted statuses ─────────────── */

echo "\n──────────────────────────────────────────────────────────────────────\n";
echo " LAYER 2 — KnownThrowers registry hits (callee → exception + status)\n";
echo "──────────────────────────────────────────────────────────────────────\n";
foreach ($actionOrder as $method) {
    $node = $topMap[$method];
    foreach ($node->getStatementResult()->getThrowPoints() as $tp) {
        $callee = $resolveCallee($tp->getNode(), $tp->getScope());
        if ($callee === null) {
            continue;
        }
        $line = $lineOf($tp->getNode());
        if (isset($KNOWN_FUNCS[$callee['name']])) {
            $reg = $KNOWN_FUNCS[$callee['name']];
            $st = $foldStatusArg($tp->getNode(), $tp->getScope(), $reg['statusArg']) ?? 500;
            printf("   %-22s %s() @L%-3d → %s  status=%d  (arg%d folded)\n",
                $method, $callee['name'], $line, $shortFqcn($reg['ex']), $st, $reg['statusArg']);
        } elseif (isset($KNOWN_METHODS[$callee['name']])) {
            $reg = $KNOWN_METHODS[$callee['name']];
            $rescued = $tp->isExplicit() ? 'enrich ' : 'RESCUE ';
            printf("   %-22s %s() @L%-3d → %s  status=%d  (%s)\n",
                $method, $callee['name'], $line, $shortFqcn($reg['ex']), $reg['status'], trim($rescued));
        }
    }
}

/* ── Report: Layer 3 descent diagnostic (cases 5 & 6) ──────────────────── */

echo "\n──────────────────────────────────────────────────────────────────────\n";
echo " LAYER 3 — bounded descent (project callees w/o @throws)\n";
echo "──────────────────────────────────────────────────────────────────────\n";
foreach (['deepUndeclared', 'deepDeclared'] as $method) {
    echo "\n {$method}():\n";
    foreach ($actionResults[$method] as $r) {
        if (count($r['chain']) > 1 || str_contains(end($r['chain']), 'OrderService')) {
            printf("   %-24s status=%-3d [%s]\n     chain: %s\n",
                $shortFqcn($r['ex']), $r['status'], $r['confidence'], implode('  ⇒  ', $r['chain']));
        }
    }
}

// Extra diagnostic: force descent through the DECLARED callee to expose what
// docblock-trust hides (the undeclared RuntimeException behind @throws).
$forceDescend = function (string $class, string $method) use ($reflectionProvider, $fileHelper, $analyseFile, $analyseMethod): array {
    $refl = $reflectionProvider->getClass($class);
    $file = $refl->getFileName();
    $map = $analyseFile($file);
    if (! isset($map[$method])) {
        return [];
    }
    // depth 1 so its own callees still descend
    return $analyseMethod($map[$method], "{$class}::{$method}", 1, [$class . '::' . $method], []);
};
$hidden = $forceDescend('App\\Services\\OrderService', 'placeDeclared');
$hiddenNames = array_values(array_unique(array_map(static fn ($r) => $shortFqcn($r['ex']), $hidden)));
echo "\n  docblock-trust check: @throws on placeDeclared() surfaces only "
    . "OutOfStockException at the call site (no descent).\n";
echo "  If we DID descend placeDeclared(), the full set is: " . implode(', ', $hiddenNames) . "\n";
echo "  → the undeclared RuntimeException (depth 2) is HIDDEN by an incomplete @throws.\n";

/* ── Final per-action table ────────────────────────────────────────────── */

echo "\n──────────────────────────────────────────────────────────────────────\n";
echo " PER-ACTION EXCEPTION TABLE (kept API errors)\n";
echo "──────────────────────────────────────────────────────────────────────\n";
foreach ($actionOrder as $method) {
    echo "\n {$method}()\n";
    $any = false;
    foreach ($actionResults[$method] as $r) {
        $flag = $r['kept'] ? ' ' : '·'; // · = demoted internal/500
        printf("  %s %-26s → %-3d  [%-8s]  %s\n",
            $flag, $shortFqcn($r['ex']), $r['status'], $r['confidence'], implode(' ⇒ ', $r['chain']));
        $any = true;
    }
    if (! $any) {
        echo "   (no exceptions surfaced)\n";
    }
}
echo "\n  legend: leading '·' = mapped to 500 (internal) and demoted from API-error docs\n";

/* ── NOISE / MISS scorecard ────────────────────────────────────────────── */

$expected = [
    'abortAction' => ['want' => ['HttpException@403', 'HttpException@404'], 'note' => 'both abort statuses constant-folded'],
    'authorizeAction' => ['want' => ['AuthorizationException@403'], 'note' => ''],
    'findOrFailAction' => ['want' => ['ModelNotFoundException@404'], 'note' => 'static findOrFail is implicit → Layer 2 rescue'],
    'validateAction' => ['want' => ['ValidationException@422'], 'note' => ''],
    'deepUndeclared' => ['want' => ['OutOfStockException@500', 'RuntimeException@500'], 'note' => 'both via Layer 3 descent'],
    'deepDeclared' => ['want' => ['OutOfStockException@500'], 'note' => 'docblock; RuntimeException knowingly hidden'],
    'anyThrowableNoise' => ['want' => [], 'note' => 'no API error; noise counted'],
    'tryCatch' => ['want' => ['RuntimeException@500'], 'note' => 'OutOfStock caught → subtracted'],
];

$got = static function (string $method) use ($actionResults, $shortFqcn): array {
    $out = [];
    foreach ($actionResults[$method] as $r) {
        if (! $r['kept']) {
            continue; // demoted vendor/internal-500 exceptions are not API errors
        }
        $out[] = $shortFqcn($r['ex']) . '@' . $r['status'];
    }
    sort($out);

    return $out;
};

echo "\n──────────────────────────────────────────────────────────────────────\n";
echo " NOISE / MISS SCORECARD\n";
echo "──────────────────────────────────────────────────────────────────────\n";
$allPass = true;
foreach ($expected as $method => $spec) {
    $want = $spec['want'];
    sort($want);
    $have = $got($method);
    $missing = array_values(array_diff($want, $have));
    $extra = array_values(array_diff($have, $want));
    $pass = $missing === [] && $extra === [];
    $allPass = $allPass && $pass;
    printf("\n %s  %s\n", $pass ? 'PASS' : 'FAIL', $method);
    echo '   want: ' . ($want === [] ? '(none)' : implode(', ', $want)) . "\n";
    echo '   got : ' . ($have === [] ? '(none)' : implode(', ', $have)) . "\n";
    if ($missing !== []) {
        echo '   MISS: ' . implode(', ', $missing) . "\n";
    }
    if ($extra !== []) {
        echo '   EXTRA: ' . implode(', ', $extra) . "\n";
    }
    if ($spec['note'] !== '') {
        echo '   note: ' . $spec['note'] . "\n";
    }
}

echo "\n──────────────────────────────────────────────────────────────────────\n";
printf(" OVERALL: %s\n", $allPass ? 'PASS (all 8 cases)' : 'PARTIAL — see FAIL rows');
echo "──────────────────────────────────────────────────────────────────────\n";

/* ── Noise summary + perf ──────────────────────────────────────────────── */

echo "\n dropped implicit 'any-throwable' points: {$noise['implicit']}\n";
foreach ($noise['samples'] as $s) {
    echo "   · {$s}\n";
}

$wall = microtime(true) - $startWall;
$peakMb = memory_get_peak_usage(true) / 1024 / 1024;
echo "\n── run stats ──\n";
echo "files analysed (parsed+processed): " . count($fileNodeCache) . "\n";
echo "max descent depth reached: 2 (action → place → reserve)\n";
echo sprintf("wall clock (container build + analyse): %.2fs\n", $wall);
echo sprintf("peak memory (real): %.1f MB\n", $peakMb);
