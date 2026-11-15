<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Tests\Fixtures\ResponseLinks;

/**
 * Prints a label for a parcel and answers with a plain array naming it — a body no model or resource
 * shapes, so nothing in the code ties its members to a column. Never dispatched.
 */
final class LabelController
{
    /**
     * @return array{parcel: int, printer: string}
     */
    public function store(): array
    {
        return ['parcel' => 1, 'printer' => 'dock-3'];
    }
}
