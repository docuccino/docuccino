<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Tests\Fixtures\Eloquent;

/**
 * A model that keeps its timestamps out of the API while inheriting the {@see Sundial}
 * `serializeDate()` override: the only date attributes it has are hidden, so the document publishes
 * none of them and none lost a `format`. Only ever reflected.
 *
 * @property int $id The milestone id.
 * @property string $name The milestone name.
 */
final class Milestone extends Sundial
{
    /**
     * @var list<string>
     */
    protected $hidden = ['created_at', 'updated_at'];
}
