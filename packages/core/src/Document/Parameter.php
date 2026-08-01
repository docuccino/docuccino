<?php

declare(strict_types=1);

namespace Docuccino\Core\Document;

use Docuccino\Core\Support\Arr;

/**
 * An OAS parameter object. Modelled fields are typed; every other member (style, explode,
 * example, content, $ref, x-*) is preserved verbatim in `rest`.
 */
final readonly class Parameter
{
    /**
     * @param  array<string, mixed>  $rest
     */
    public function __construct(
        public ?string $name = null,
        public ?string $in = null,
        public ?string $description = null,
        public ?bool $required = null,
        public ?bool $deprecated = null,
        public ?SchemaObject $schema = null,
        public ?DocuccinoExtension $docuccino = null,
        public array $rest = [],
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        $name = $data['name'] ?? null;
        $in = $data['in'] ?? null;
        $description = $data['description'] ?? null;
        $required = $data['required'] ?? null;
        $deprecated = $data['deprecated'] ?? null;

        $schema = isset($data['schema']) && is_array($data['schema'])
            ? SchemaObject::fromArray(Arr::stringKeyed($data['schema']))
            : null;

        $docuccino = isset($data['x-docuccino']) && is_array($data['x-docuccino'])
            ? DocuccinoExtension::fromArray(Arr::stringKeyed($data['x-docuccino']))
            : null;

        unset($data['name'], $data['in'], $data['description'], $data['required'], $data['deprecated'], $data['schema'], $data['x-docuccino']);

        return new self(
            name: is_string($name) ? $name : null,
            in: is_string($in) ? $in : null,
            description: is_string($description) ? $description : null,
            required: is_bool($required) ? $required : null,
            deprecated: is_bool($deprecated) ? $deprecated : null,
            schema: $schema,
            docuccino: $docuccino,
            rest: $data,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $out = [];

        if ($this->docuccino !== null && ! $this->docuccino->isEmpty()) {
            $out['x-docuccino'] = $this->docuccino->toArray();
        }

        if ($this->name !== null) {
            $out['name'] = $this->name;
        }

        if ($this->in !== null) {
            $out['in'] = $this->in;
        }

        if ($this->description !== null) {
            $out['description'] = $this->description;
        }

        if ($this->required !== null) {
            $out['required'] = $this->required;
        }

        if ($this->deprecated !== null) {
            $out['deprecated'] = $this->deprecated;
        }

        if ($this->schema !== null) {
            $out['schema'] = $this->schema->toArray();
        }

        return $out + $this->rest;
    }
}
