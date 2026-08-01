<?php

declare(strict_types=1);

namespace Docuccino\Core\Document;

use Docuccino\Core\Support\Arr;

/**
 * An OAS operation object. Parameters and responses are modelled; every other member
 * (requestBody, callbacks, externalDocs, servers, x-*) is preserved verbatim in `rest`.
 */
final readonly class Operation
{
    /**
     * @param  list<string>  $tags
     * @param  list<Parameter>  $parameters
     * @param  array<string, ResponseObject>  $responses
     * @param  list<array<string, mixed>>|null  $security
     * @param  array<string, mixed>  $rest
     */
    public function __construct(
        public ?string $operationId = null,
        public ?string $summary = null,
        public ?string $description = null,
        public array $tags = [],
        public ?bool $deprecated = null,
        public array $parameters = [],
        public array $responses = [],
        public ?array $security = null,
        public ?DocuccinoExtension $docuccino = null,
        public array $rest = [],
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        $operationId = $data['operationId'] ?? null;
        $summary = $data['summary'] ?? null;
        $description = $data['description'] ?? null;
        $deprecated = $data['deprecated'] ?? null;

        $tags = [];
        if (isset($data['tags']) && is_array($data['tags'])) {
            foreach ($data['tags'] as $tag) {
                if (is_string($tag)) {
                    $tags[] = $tag;
                }
            }
        }

        $parameters = [];
        if (isset($data['parameters']) && is_array($data['parameters'])) {
            foreach ($data['parameters'] as $parameter) {
                if (is_array($parameter)) {
                    $parameters[] = Parameter::fromArray(Arr::stringKeyed($parameter));
                }
            }
        }

        $responses = [];
        if (isset($data['responses']) && is_array($data['responses'])) {
            foreach ($data['responses'] as $status => $response) {
                if (is_array($response)) {
                    $responses[(string) $status] = ResponseObject::fromArray(Arr::stringKeyed($response));
                }
            }
        }

        $security = null;
        if (isset($data['security']) && is_array($data['security'])) {
            $security = [];
            foreach ($data['security'] as $requirement) {
                if (is_array($requirement)) {
                    /** @var array<string, mixed> $requirement */
                    $security[] = $requirement;
                }
            }
        }

        $docuccino = isset($data['x-docuccino']) && is_array($data['x-docuccino'])
            ? DocuccinoExtension::fromArray(Arr::stringKeyed($data['x-docuccino']))
            : null;

        unset($data['operationId'], $data['summary'], $data['description'], $data['deprecated'], $data['tags'], $data['parameters'], $data['responses'], $data['security'], $data['x-docuccino']);

        return new self(
            operationId: is_string($operationId) ? $operationId : null,
            summary: is_string($summary) ? $summary : null,
            description: is_string($description) ? $description : null,
            tags: $tags,
            deprecated: is_bool($deprecated) ? $deprecated : null,
            parameters: $parameters,
            responses: $responses,
            security: $security,
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

        if ($this->operationId !== null) {
            $out['operationId'] = $this->operationId;
        }

        if ($this->summary !== null) {
            $out['summary'] = $this->summary;
        }

        if ($this->description !== null) {
            $out['description'] = $this->description;
        }

        if ($this->deprecated !== null) {
            $out['deprecated'] = $this->deprecated;
        }

        if ($this->tags !== []) {
            $out['tags'] = $this->tags;
        }

        if ($this->security !== null) {
            $out['security'] = $this->security;
        }

        if ($this->parameters !== []) {
            $out['parameters'] = array_map(
                static fn (Parameter $parameter): array => $parameter->toArray(),
                $this->parameters,
            );
        }

        if ($this->responses !== []) {
            $responses = [];
            foreach ($this->responses as $status => $response) {
                $responses[$status] = $response->toArray();
            }
            $out['responses'] = $responses;
        }

        return $out + $this->rest;
    }
}
