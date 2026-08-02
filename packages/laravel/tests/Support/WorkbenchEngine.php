<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Tests\Support;

use Docuccino\Core\Inference\ActionAnalysis;
use Docuccino\Core\Inference\ClassMetadata;
use Docuccino\Core\Inference\DType\ArrayShapeField;
use Docuccino\Core\Inference\DType\ArrayShapeT;
use Docuccino\Core\Inference\DType\ClassT;
use Docuccino\Core\Inference\DType\EnumT;
use Docuccino\Core\Inference\DType\ListT;
use Docuccino\Core\Inference\DType\LiteralT;
use Docuccino\Core\Inference\DType\NullT;
use Docuccino\Core\Inference\DType\ScalarT;
use Docuccino\Core\Inference\DType\UnionT;
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
    public static function make(): StubTypeEngine
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

        return new StubTypeEngine(
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
            ],
            classes: [
                'Workbench\\App\\Data\\FormData' => $formData,
                'Workbench\\App\\Data\\WidgetData' => $widgetData,
            ],
        );
    }
}
