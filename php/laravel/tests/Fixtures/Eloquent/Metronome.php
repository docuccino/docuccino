<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Tests\Fixtures\Eloquent;

/**
 * A model under the {@see Sundial} `serializeDate()` override whose only date attribute names its OWN
 * wire format. Laravel formats such a column with the cast parameter and never reaches the hook, so
 * nothing here lost a format: the column publishes what the parameter writes, and the model earns no
 * date-serialisation notice at all. Only ever reflected.
 *
 * @property int $id The metronome id.
 * @property string $title The metronome title.
 */
final class Metronome extends Sundial
{
    /** No timestamp columns, so the parameterised cast is the only date attribute there is. */
    public $timestamps = false;

    /**
     * @var array<string, string>
     */
    protected $casts = [
        'beat_on' => 'date:Y-m-d',
    ];
}
