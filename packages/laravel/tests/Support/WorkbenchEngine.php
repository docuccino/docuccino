<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Tests\Support;

use Docuccino\Core\Inference\ActionAnalysis;
use Docuccino\Core\Inference\ClassMetadata;
use Docuccino\Core\Inference\DType\ArrayShapeField;
use Docuccino\Core\Inference\DType\ArrayShapeT;
use Docuccino\Core\Inference\DType\ClassT;
use Docuccino\Core\Inference\DType\DType;
use Docuccino\Core\Inference\DType\EnumT;
use Docuccino\Core\Inference\DType\ListT;
use Docuccino\Core\Inference\DType\LiteralT;
use Docuccino\Core\Inference\DType\NullT;
use Docuccino\Core\Inference\DType\ScalarT;
use Docuccino\Core\Inference\DType\UnionT;
use Docuccino\Core\Inference\DType\VoidT;
use Docuccino\Core\Inference\PropertyMetadata;
use Docuccino\Core\Inference\ReturnSite;
use Docuccino\Core\Inference\SourceLocation;
use Docuccino\Core\Inference\ThrowConfidence;
use Docuccino\Core\Inference\ThrowDisposition;
use Docuccino\Core\Inference\ThrownException;
use Docuccino\Core\Inference\TypeEngine;
use Docuccino\Core\Tests\Support\StubTypeEngine;

/**
 * Builds the deterministic stub {@see TypeEngine} the feature tests
 * bind for the workbench — canned return types and thrown exceptions that stand in for what the
 * real PHPStan engine would recover (JsonResponse-payload unwrapping is a Phase 4 integration, so
 * the stub supplies the already-unwrapped shapes).
 */
final class WorkbenchEngine
{
    /**
     * @param  array<string, ActionAnalysis>  $callables  scripted CallableRef analyses (keyed by
     *                                                    CallableRef::symbol()) for the inferred-handler tier tests
     */
    public static function make(array $callables = []): StubTypeEngine
    {
        $location = new SourceLocation('');

        $formData = new ClassMetadata('Workbench\\App\\Data\\FormData', [
            new PropertyMetadata('id', ScalarT::int()),
            new PropertyMetadata('title', ScalarT::string()),
            new PropertyMetadata('publishedAt', UnionT::of([ScalarT::string(), new NullT])),
        ]);

        $widgetData = new ClassMetadata('Workbench\\App\\Data\\WidgetData', [
            new PropertyMetadata('id', ScalarT::int()),
            new PropertyMetadata('name', ScalarT::string()),
            new PropertyMetadata('status', new EnumT('Workbench\\App\\Enums\\WidgetStatus', ['Draft', 'Published', 'Archived'])),
        ]);

        // The FormRequest's rules() as the engine recovers it — a constant array shape (never executed).
        $storeWidgetRules = new ArrayShapeT([
            new ArrayShapeField('name', new LiteralT('required|string|max:100')),
            new ArrayShapeField('quantity', new LiteralT('required|integer|min:1')),
            new ArrayShapeField('avatar', new LiteralT('nullable|image')),
            new ArrayShapeField('role', new LiteralT('required|in:admin,user')),
        ]);

        // A JsonResponse<payload, status> as the bundled PHPStan extension recovers it.
        $jsonResponse = static fn (DType $payload, int $status): ClassT => new ClassT(
            'Illuminate\\Http\\JsonResponse',
            [$payload, new LiteralT($status)],
        );

        $missing = new ClassT('Illuminate\\Http\\Resources\\MissingValue');

        return new StubTypeEngine(
            traces: [
                // Scripts the Query Builder trace so the golden exercises the QB integration
                // deterministically (the stub engine has no real trace) — mirrors the Spike-B chain.
                'Workbench\\App\\Http\\Controllers\\WidgetQueryController::index' => QbTraceScript::forChain(
                    "QueryBuilder::for(Form::class)->allowedFilters(['name', AllowedFilter::exact('status')])->allowedSorts(['name', 'created_at'])->defaultSort('name')->paginate(20)",
                ),
            ],
            analyses: [
                'Workbench\\App\\Http\\Requests\\StoreWidgetRequest::rules' => new ActionAnalysis(
                    returns: [new ReturnSite($storeWidgetRules, $location)],
                ),
                'Workbench\\App\\Http\\Controllers\\FormController::index' => new ActionAnalysis(
                    returns: [new ReturnSite(new ListT(new ClassT('Workbench\\App\\Data\\FormData')), $location)],
                ),
                'Workbench\\App\\Http\\Controllers\\FormController::show' => new ActionAnalysis(
                    returns: [new ReturnSite(new ClassT('Workbench\\App\\Data\\FormData'), $location)],
                    throws: [new ThrownException(
                        'Illuminate\\Database\\Eloquent\\ModelNotFoundException',
                        404,
                        [],
                        ThrowConfidence::Certain,
                        ThrowDisposition::Signal,
                    )],
                ),

                // Spatie Data: request body from the Data class + a Data response under a folded 201.
                self::CONTROLLER.'storeArticle' => new ActionAnalysis(
                    returns: [new ReturnSite($jsonResponse(new ClassT(self::ARTICLE_DATA), 201), $location)],
                ),
                // API Resources: an anonymous collection, and a single resource with whenLoaded fields.
                self::CONTROLLER.'listArticleResources' => new ActionAnalysis(
                    returns: [new ReturnSite(new ClassT('Illuminate\\Http\\Resources\\Json\\AnonymousResourceCollection', [new ClassT(self::ARTICLE_RESOURCE)]), $location)],
                ),
                self::CONTROLLER.'showArticleResource' => new ActionAnalysis(
                    returns: [new ReturnSite(new ClassT(self::ARTICLE_RESOURCE), $location)],
                ),
                self::ARTICLE_RESOURCE.'::toArray' => new ActionAnalysis(returns: [new ReturnSite(new ArrayShapeT([
                    new ArrayShapeField('id', ScalarT::int()),
                    new ArrayShapeField('title', ScalarT::string()),
                    new ArrayShapeField('author', UnionT::of([new ClassT(self::AUTHOR_RESOURCE), $missing])),
                    new ArrayShapeField('excerpt', UnionT::of([ScalarT::string(), $missing, new NullT])),
                ]), $location)]),
                self::AUTHOR_RESOURCE.'::toArray' => new ActionAnalysis(returns: [new ReturnSite(new ArrayShapeT([
                    new ArrayShapeField('name', ScalarT::string()),
                    new ArrayShapeField('email', ScalarT::string()),
                ]), $location)]),

                // JSON:API: the resource + its to* member shapes.
                self::CONTROLLER.'showJsonApiArticle' => new ActionAnalysis(
                    returns: [new ReturnSite(new ClassT(self::JSONAPI_RESOURCE), $location)],
                ),
                self::JSONAPI_RESOURCE.'::toAttributes' => new ActionAnalysis(returns: [new ReturnSite(new ArrayShapeT([
                    new ArrayShapeField('title', ScalarT::string()),
                    new ArrayShapeField('body', ScalarT::string()),
                ]), $location)]),
                self::JSONAPI_RESOURCE.'::toRelationships' => new ActionAnalysis(returns: [new ReturnSite(new ArrayShapeT([
                    new ArrayShapeField('author', ScalarT::string()),
                ]), $location)]),
                self::JSONAPI_RESOURCE.'::toLinks' => new ActionAnalysis(returns: [new ReturnSite(new ArrayShapeT([
                    new ArrayShapeField('self', ScalarT::string()),
                ]), $location)]),

                // Eloquent model response, and a noContent() 204.
                self::CONTROLLER.'showWidget' => new ActionAnalysis(
                    returns: [new ReturnSite($jsonResponse(new ClassT(self::WIDGET_MODEL), 200), $location)],
                ),
                self::CONTROLLER.'destroyWidget' => new ActionAnalysis(
                    returns: [new ReturnSite($jsonResponse(new VoidT, 204), $location)],
                ),

                // Distinct return paths carrying distinct statuses (200 + 202).
                self::CONTROLLER.'storeReport' => new ActionAnalysis(returns: [
                    new ReturnSite($jsonResponse(new ArrayShapeT([new ArrayShapeField('id', ScalarT::int())]), 200), $location),
                    new ReturnSite($jsonResponse(new ArrayShapeT([new ArrayShapeField('status', new LiteralT('accepted'))]), 202), $location),
                ]),
            ],
            classes: [
                'Workbench\\App\\Data\\FormData' => $formData,
                'Workbench\\App\\Data\\WidgetData' => $widgetData,
                self::ARTICLE_DATA => new ClassMetadata(self::ARTICLE_DATA, [
                    new PropertyMetadata('id', ScalarT::int()),
                    new PropertyMetadata('title', ScalarT::string()),
                    new PropertyMetadata('body', ScalarT::string()),
                    new PropertyMetadata('secret', ScalarT::string()),
                    new PropertyMetadata('internal', ScalarT::int()),
                    new PropertyMetadata('subtitle', UnionT::of([ScalarT::string(), new ClassT('Spatie\\LaravelData\\Optional')])),
                    new PropertyMetadata('author', UnionT::of([new ClassT(self::AUTHOR_DATA), new NullT])),
                ]),
                self::AUTHOR_DATA => new ClassMetadata(self::AUTHOR_DATA, [
                    new PropertyMetadata('name', ScalarT::string()),
                    new PropertyMetadata('email', ScalarT::string()),
                ]),
                self::WIDGET_MODEL => new ClassMetadata(self::WIDGET_MODEL, [
                    new PropertyMetadata('id', ScalarT::int()),
                    new PropertyMetadata('name', ScalarT::string()),
                    new PropertyMetadata('password', ScalarT::string()),
                    new PropertyMetadata('token', ScalarT::string()),
                    new PropertyMetadata('created_at', UnionT::of([ScalarT::string(), new NullT])),
                    new PropertyMetadata('is_active', ScalarT::string()),
                    new PropertyMetadata('status', ScalarT::string()),
                    new PropertyMetadata('meta', ScalarT::string()),
                ]),
            ],
            callables: $callables,
        );
    }

    private const CONTROLLER = 'Workbench\\App\\Http\\Controllers\\IntegrationsController::';

    private const ARTICLE_DATA = 'Docuccino\\Laravel\\Tests\\Fixtures\\SpatieData\\ArticleData';

    private const AUTHOR_DATA = 'Docuccino\\Laravel\\Tests\\Fixtures\\SpatieData\\AuthorData';

    private const ARTICLE_RESOURCE = 'Docuccino\\Laravel\\Tests\\Fixtures\\ApiResources\\ArticleResource';

    private const AUTHOR_RESOURCE = 'Docuccino\\Laravel\\Tests\\Fixtures\\ApiResources\\AuthorResource';

    private const JSONAPI_RESOURCE = 'Docuccino\\Laravel\\Tests\\Fixtures\\ApiResources\\ArticleJsonApiResource';

    private const WIDGET_MODEL = 'Docuccino\\Laravel\\Tests\\Fixtures\\Eloquent\\Widget';
}
