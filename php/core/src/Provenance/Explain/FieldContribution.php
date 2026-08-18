<?php

declare(strict_types=1);

namespace Docuccino\Core\Provenance\Explain;

use Docuccino\Core\Patch\Layer;
use Docuccino\Core\Provenance\Source;

/**
 * One layer's attempt at one field, as a reader sees it: who wrote it, at which rung, what value it
 * carried, and whether that value is the one the document publishes.
 *
 * A losing contribution keeps only what `overrode` recorded, so its source is always null — the trail
 * remembers the value that was displaced, never where it came from.
 *
 * @internal
 */
final readonly class FieldContribution
{
    public function __construct(
        public string $producer,
        public Layer $layer,
        public bool $won,
        public mixed $value = null,
        public ?Source $source = null,
        public ?float $confidence = null,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $out = [
            'producer' => $this->producer,
            'layer' => $this->layer->label(),
            'rank' => $this->layer->value,
            'won' => $this->won,
        ];

        if ($this->value !== null) {
            $out['value'] = $this->value;
        }

        if ($this->source !== null) {
            $out['source'] = $this->source->toArray();
        }

        if ($this->confidence !== null) {
            $out['confidence'] = $this->confidence;
        }

        return $out;
    }
}
