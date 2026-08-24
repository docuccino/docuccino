<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Tests\Fixtures\DeclaredErrors;

use Docuccino\Attributes\ErrorComponent;
use Docuccino\Attributes\Response;

/**
 * Routes whose thrown exceptions the suite scripts on the stub engine. The bodies come from the error
 * tiers, so the actions themselves only need to exist and be routable.
 *
 * The last two actions carry `#[ErrorComponent]` on the ACTION — a placement `TARGET_METHOD` permits
 * and nothing reads — which is what those rows are for.
 */
final class DeclaredErrorsController
{
    public function first(): array
    {
        return [];
    }

    public function second(): array
    {
        return [];
    }

    public function third(): array
    {
        return [];
    }

    public function fourth(): array
    {
        return [];
    }

    #[ErrorComponent('ActionNamed')]
    public function fifth(): array
    {
        return [];
    }

    #[ErrorComponent('ActionNamed')]
    public function sixth(): array
    {
        return [];
    }

    #[ErrorComponent('ActionNamed')]
    #[Response(status: 410, type: 'array{reason: string}', description: 'Gone')]
    public function seventh(): array
    {
        return [];
    }

    #[ErrorComponent('ActionNamed')]
    #[Response(status: 410, type: 'array{reason: string}', description: 'Gone')]
    public function eighth(): array
    {
        return [];
    }

    #[Response(status: 410, type: 'array{reason: string}', description: 'Gone', component: 'DeclaredGone')]
    public function ninth(): array
    {
        return [];
    }

    #[Response(status: 410, type: 'array{reason: string}', description: 'Gone', component: 'DeclaredGone')]
    public function tenth(): array
    {
        return [];
    }

    #[Response(status: 410, type: 'array{reason: string}', description: 'Gone', component: 'DeclaredGone')]
    #[Response(status: 410, mediaType: 'application/problem+json', component: 'SecondName')]
    public function eleventh(): array
    {
        return [];
    }

    /** Names the 409 the error tiers build, over the `#[ErrorComponent]` its thrown exception declares. */
    #[Response(status: 409, description: 'Conflict', component: 'DeclaredConflict')]
    public function twelfth(): array
    {
        return [];
    }
}
