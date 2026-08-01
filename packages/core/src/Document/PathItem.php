<?php

declare(strict_types=1);

namespace Docuccino\Core\Document;

use Docuccino\Core\Support\Arr;

/**
 * An OAS path item: shared parameters plus one operation per HTTP method.
 */
final readonly class PathItem
{
    /**
     * Canonical HTTP method order (design §3).
     *
     * @var list<string>
     */
    public const array METHODS = ['get', 'put', 'post', 'delete', 'options', 'head', 'patch', 'trace', 'query'];

    /**
     * @param  array<string, Operation>  $operations
     * @param  list<Parameter>  $parameters
     * @param  array<string, mixed>  $rest
     */
    public function __construct(
        public array $operations = [],
        public array $parameters = [],
        public ?DocuccinoExtension $docuccino = null,
        public array $rest = [],
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        $operations = [];
        foreach (self::METHODS as $method) {
            if (isset($data[$method]) && is_array($data[$method])) {
                $operations[$method] = Operation::fromArray(Arr::stringKeyed($data[$method]));
            }
            unset($data[$method]);
        }

        $parameters = [];
        if (isset($data['parameters']) && is_array($data['parameters'])) {
            foreach ($data['parameters'] as $parameter) {
                if (is_array($parameter)) {
                    $parameters[] = Parameter::fromArray(Arr::stringKeyed($parameter));
                }
            }
        }
        unset($data['parameters']);

        $docuccino = isset($data['x-docuccino']) && is_array($data['x-docuccino'])
            ? DocuccinoExtension::fromArray(Arr::stringKeyed($data['x-docuccino']))
            : null;
        unset($data['x-docuccino']);

        return new self(
            operations: $operations,
            parameters: $parameters,
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

        $out += $this->rest;

        if ($this->parameters !== []) {
            $out['parameters'] = array_map(
                static fn (Parameter $parameter): array => $parameter->toArray(),
                $this->parameters,
            );
        }

        foreach ($this->operations as $method => $operation) {
            $out[$method] = $operation->toArray();
        }

        return $out;
    }
}
