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
 *   php engine-runner.php analyze         <controllerFile> <class> <method>
 *   php engine-runner.php trace-qb        <controllerFile> <class> <method>
 *   php engine-runner.php trace-qb-enrich <controllerFile> <class> <method>
 *   php engine-runner.php class-metadata  <ignored>        <class>
 *   php engine-runner.php analyze-callable <file> <class> <method> <line> <narrowParam> <narrowType>
 *
 * Emits `@@RESULT@@` followed by a single JSON line (so any incidental host
 * output before it is ignored by the caller).
 */

use Docuccino\Core\Extensions\Schema\EnumReflection;
use Docuccino\Core\Inference\ActionRef;
use Docuccino\Core\Inference\CallableRef;
use Docuccino\Core\Inference\ClassRef;
use Docuccino\Inference\PhpStan\Analysis\EngineConfig;
use Docuccino\Inference\PhpStan\Analysis\PhpStanEngineFactory;
use Docuccino\Inference\PhpStan\Runtime\RuntimeConfig;
use Docuccino\Inference\PhpStan\Tests\Support\QueryBuilderProbe;
use Docuccino\Laravel\Integrations\FormRequest\RulesMethodVisitor;
use Docuccino\Laravel\Integrations\JsonApiPaginate\JsonApiPaginateTraceVisitor;
use Docuccino\Laravel\Integrations\QueryBuilder\FilterColumnResolver;
use Docuccino\Laravel\Integrations\QueryBuilder\QbEntry;
use Docuccino\Laravel\Integrations\QueryBuilder\QueryBuilderTraceVisitor;

$repoRoot = dirname(__DIR__, 4);
$app = $repoRoot.'/tests/fixture-app/app';

require $app.'/vendor/autoload.php';

// Hand-registered PSR-4 for the packages under test — no root composer vendor is
// loaded here, so the only phpstan/php-parser in play is the fixture app's.
spl_autoload_register(static function (string $class) use ($repoRoot): void {
    $map = [
        'Docuccino\\Attributes\\' => $repoRoot.'/packages/attributes/src/',
        'Docuccino\\Core\\' => $repoRoot.'/packages/core/src/',
        'Docuccino\\Inference\\PhpStan\\Tests\\' => $repoRoot.'/packages/inference-phpstan/tests/',
        'Docuccino\\Inference\\PhpStan\\' => $repoRoot.'/packages/inference-phpstan/src/',
        // The JSON:API-paginate trace visitor lives adapter-side but imports only core + php-parser
        // (+ its own dep-free Facts), so it runs here to prove terminal/receiver matching on the real
        // engine (spike-d / Phase 5c M2).
        'Docuccino\\Laravel\\' => $repoRoot.'/packages/laravel/src/',
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
$line = (int) ($argv[5] ?? 0);
$narrowParam = ($argv[6] ?? '') === '' ? null : $argv[6];
$narrowType = ($argv[7] ?? '') === '' ? null : $argv[7];

$tmp = sys_get_temp_dir().'/docuccino-runner-'.getmypid();
@mkdir($tmp, 0777, true);

$engine = (new PhpStanEngineFactory)->create(
    new RuntimeConfig($app, $tmp, PHP_VERSION_ID, [$app.'/app']),
    EngineConfig::forProject($app.'/app'),
);

$ref = new ActionRef($file, $class === '' ? null : $class, $method);

$result = match ($mode) {
    'analyze' => $engine->analyzeAction($ref)->toArray(),
    'analyze-callable' => $engine->analyzeCallable(new CallableRef(
        $file,
        $class === '' ? null : $class,
        $method === '' ? null : $method,
        $line,
        $narrowParam,
        $narrowType,
    ))->toArray(),
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
    'trace-qb-enrich' => (static function () use ($engine, $ref): array {
        // The REAL QueryBuilder trace visitor + the REAL cast-recovery resolver, run inside the host
        // app's process where its models/enums are autoloadable: proves an enum-cast column recovers
        // to its emitted enum-filter shape (backing values + case descriptions) end-to-end.
        $visitor = new QueryBuilderTraceVisitor;
        $engine->trace($ref, $visitor);
        $facts = $visitor->facts;

        $resolver = new FilterColumnResolver;
        $filters = array_map(static function (QbEntry $filter) use ($resolver, $facts): array {
            $column = $filter->kind === 'exact' && $facts->subjectModel !== null
                ? $resolver->resolve($facts->subjectModel, $filter->column())
                : null;

            return [
                'name' => $filter->name,
                'kind' => $filter->kind,
                'columnKind' => $column?->kind,
                'enum' => $column?->enum,
                'values' => $column?->enum !== null ? EnumReflection::values($column->enum) : [],
                'descriptions' => $column?->enum !== null ? EnumReflection::descriptions($column->enum) : [],
                'dependencyBasenames' => array_map('basename', $column?->dependencyFiles ?? []),
                'scalarSchema' => $column?->scalarSchema,
            ];
        }, $facts->filters);

        return ['subjectModel' => $facts->subjectModel, 'filters' => $filters];
    })(),
    'trace-rules' => (static function () use ($engine, $ref): array {
        // The REAL RulesMethodVisitor runs in the engine subprocess: it must recover a rules()
        // method's returned array with AST-level constant folding so Rule::enum(...) descriptors
        // survive (validation §1). Returns each field's recovered rule names + params, plus the
        // fields present but unrecoverable.
        $visitor = new RulesMethodVisitor;
        $engine->trace($ref, $visitor);

        $fields = [];
        foreach ($visitor->ruleSet()->fields as $field => $rules) {
            $fields[$field] = array_map(static fn ($rule): array => [
                'name' => $rule->name,
                'parameters' => $rule->parameters,
                'note' => $rule->note,
            ], $rules);
        }

        return ['fields' => $fields, 'unrecoverable' => $visitor->unrecoverableFields()];
    })(),
    'trace-json-api-paginate' => (static function () use ($engine, $ref): array {
        $visitor = new JsonApiPaginateTraceVisitor;
        $engine->trace($ref, $visitor);

        return [
            'paginates' => $visitor->facts->paginates,
            'maxResults' => $visitor->facts->maxResultsOverride,
            'defaultSize' => $visitor->facts->defaultSizeOverride,
        ];
    })(),
    default => ['error' => 'unknown mode: '.$mode],
};

fwrite(STDOUT, "\n@@RESULT@@".json_encode($result, JSON_THROW_ON_ERROR)."\n");
