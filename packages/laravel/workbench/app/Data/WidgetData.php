<?php

declare(strict_types=1);

namespace Workbench\App\Data;

/**
 * The data object named by `#[Response(type: WidgetData::class)]` on the widget store route.
 */
final class WidgetData
{
    public function __construct(
        public int $id,
        public string $name,
    ) {}
}
