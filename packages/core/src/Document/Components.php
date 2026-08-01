<?php

declare(strict_types=1);

namespace Docuccino\Core\Document;

use Docuccino\Core\Support\Arr;

/**
 * The OAS components object. Reusable schemas/responses/parameters are modelled; the
 * remaining sections (examples, headers, securitySchemes, …) are preserved in `rest`.
 */
final readonly class Components
{
    /**
     * @param  array<string, SchemaObject>  $schemas
     * @param  array<string, ResponseObject>  $responses
     * @param  array<string, Parameter>  $parameters
     * @param  array<string, mixed>  $rest
     */
    public function __construct(
        public array $schemas = [],
        public array $responses = [],
        public array $parameters = [],
        public array $rest = [],
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        $schemas = [];
        if (isset($data['schemas']) && is_array($data['schemas'])) {
            foreach ($data['schemas'] as $name => $schema) {
                if (is_array($schema)) {
                    $schemas[(string) $name] = SchemaObject::fromArray(Arr::stringKeyed($schema));
                }
            }
        }

        $responses = [];
        if (isset($data['responses']) && is_array($data['responses'])) {
            foreach ($data['responses'] as $name => $response) {
                if (is_array($response)) {
                    $responses[(string) $name] = ResponseObject::fromArray(Arr::stringKeyed($response));
                }
            }
        }

        $parameters = [];
        if (isset($data['parameters']) && is_array($data['parameters'])) {
            foreach ($data['parameters'] as $name => $parameter) {
                if (is_array($parameter)) {
                    $parameters[(string) $name] = Parameter::fromArray(Arr::stringKeyed($parameter));
                }
            }
        }

        unset($data['schemas'], $data['responses'], $data['parameters']);

        return new self(
            schemas: $schemas,
            responses: $responses,
            parameters: $parameters,
            rest: $data,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $out = [];

        if ($this->schemas !== []) {
            $schemas = [];
            foreach ($this->schemas as $name => $schema) {
                $schemas[$name] = $schema->toArray();
            }
            $out['schemas'] = $schemas;
        }

        if ($this->responses !== []) {
            $responses = [];
            foreach ($this->responses as $name => $response) {
                $responses[$name] = $response->toArray();
            }
            $out['responses'] = $responses;
        }

        if ($this->parameters !== []) {
            $parameters = [];
            foreach ($this->parameters as $name => $parameter) {
                $parameters[$name] = $parameter->toArray();
            }
            $out['parameters'] = $parameters;
        }

        return $out + $this->rest;
    }

    public function isEmpty(): bool
    {
        return $this->schemas === []
            && $this->responses === []
            && $this->parameters === []
            && $this->rest === [];
    }
}
