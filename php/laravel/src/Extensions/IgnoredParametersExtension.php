<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Extensions;

use Docuccino\Attributes\IgnoreParam;
use Docuccino\Core\Draft\OperationDraft;
use Docuccino\Core\Extensions\Context\RouteContext;
use Docuccino\Core\Extensions\Contracts\OperationExtension;
use Docuccino\Core\Extensions\Contracts\OperationPhase;
use Docuccino\Core\Extensions\Ordering\ExtensionOrder;
use Docuccino\Core\Extensions\Ordering\Priorities;

/**
 * Applies `#[IgnoreParam]`. It is the only subtractive parameter pass, so it runs in Finalize, after
 * every producer that could write a parameter: the parameter phase's own extensions, the request phase's
 * validation recovery, and the parameter attributes. Removing a node before its producer runs removes
 * nothing, because the producer creates it again.
 *
 * It sits ahead of the example pass inside Finalize, so an `#[Example(parameter: …)]` naming something
 * this dropped reports a missing target rather than illustrating a parameter the document no longer has.
 */
#[ExtensionOrder(priority: Priorities::FIRST)]
final class IgnoredParametersExtension implements OperationExtension
{
    /** The OAS parameter locations, which is where a declaration naming none of them applies. */
    private const array LOCATIONS = ['cookie', 'header', 'path', 'query'];

    public function phase(): OperationPhase
    {
        return OperationPhase::Finalize;
    }

    public function handle(OperationDraft $operation, RouteContext $context): void
    {
        foreach ($context->attributes->all(IgnoreParam::class) as $ignore) {
            foreach ($ignore->in === null ? self::LOCATIONS : [$ignore->in] as $location) {
                $operation->removeParameter($location, $ignore->name);
            }
        }
    }
}
