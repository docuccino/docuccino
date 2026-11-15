<?php

declare(strict_types=1);

namespace Docuccino\Core\Links;

/**
 * One column of one resource that a response body carries verbatim, at a JSON pointer into the body —
 * the other half of a {@see BindingTake}, matched on the same resource and key.
 *
 * @internal
 */
final readonly class BodyGive
{
    public function __construct(
        public string $status,
        public string $resource,
        public string $key,
        public string $pointer,
    ) {}

    public function token(): string
    {
        return $this->resource."\0".$this->key;
    }

    /**
     * @return array<string, string>
     */
    public function toArray(): array
    {
        return ['status' => $this->status, 'resource' => $this->resource, 'key' => $this->key, 'pointer' => $this->pointer];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): ?self
    {
        foreach (['status', 'resource', 'key', 'pointer'] as $member) {
            if (! is_string($data[$member] ?? null) || $data[$member] === '') {
                return null;
            }
        }

        /** @var array{status: string, resource: string, key: string, pointer: string} $data */
        return new self($data['status'], $data['resource'], $data['key'], $data['pointer']);
    }
}
