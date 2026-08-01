<?php

declare(strict_types=1);

namespace Docuccino\Core\Document;

use Docuccino\Core\Support\Arr;

/**
 * An OAS response object (or a `$ref` to one). Non-modelled members survive in `rest`.
 */
final readonly class ResponseObject
{
    /**
     * @param  array<string, mixed>|null  $headers
     * @param  array<string, mixed>|null  $content
     * @param  array<string, mixed>  $rest
     */
    public function __construct(
        public ?string $ref = null,
        public ?string $description = null,
        public ?array $headers = null,
        public ?array $content = null,
        public ?DocuccinoExtension $docuccino = null,
        public array $rest = [],
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        $ref = $data['$ref'] ?? null;
        $description = $data['description'] ?? null;

        $headers = null;
        if (isset($data['headers']) && is_array($data['headers'])) {
            /** @var array<string, mixed> $headers */
            $headers = $data['headers'];
        }

        $content = null;
        if (isset($data['content']) && is_array($data['content'])) {
            /** @var array<string, mixed> $content */
            $content = $data['content'];
        }

        $docuccino = isset($data['x-docuccino']) && is_array($data['x-docuccino'])
            ? DocuccinoExtension::fromArray(Arr::stringKeyed($data['x-docuccino']))
            : null;

        unset($data['$ref'], $data['description'], $data['headers'], $data['content'], $data['x-docuccino']);

        return new self(
            ref: is_string($ref) ? $ref : null,
            description: is_string($description) ? $description : null,
            headers: $headers,
            content: $content,
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

        if ($this->ref !== null) {
            $out['$ref'] = $this->ref;
        }

        if ($this->description !== null) {
            $out['description'] = $this->description;
        }

        if ($this->headers !== null) {
            $out['headers'] = $this->headers;
        }

        if ($this->content !== null) {
            $out['content'] = $this->content;
        }

        return $out + $this->rest;
    }
}
