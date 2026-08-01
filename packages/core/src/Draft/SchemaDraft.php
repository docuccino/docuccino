<?php

declare(strict_types=1);

namespace Docuccino\Core\Draft;

use Docuccino\Core\Document\NodeExtension;
use Docuccino\Core\Document\SchemaObject;
use Docuccino\Core\Patch\Contribution;
use Docuccino\Core\Patch\PatchGuard;
use Docuccino\Core\Patch\PatchResult;

/**
 * A mutable JSON Schema builder. Scalar keywords (type, format, enum, required, …) go through
 * the guard; nested object properties merge by name so a later layer patches a single
 * property without discarding inferred siblings (design §7).
 */
final class SchemaDraft
{
    private readonly PatchGuard $guard;

    /**
     * @var array<string, SchemaDraft>
     */
    private array $properties = [];

    private ?string $id = null;

    /**
     * @var array<string, mixed>|null
     */
    private ?array $mock = null;

    public function __construct()
    {
        $this->guard = new PatchGuard;
    }

    public function set(string $keyword, mixed $value, Contribution $by): PatchResult
    {
        return $this->guard->apply($keyword, $value, $by);
    }

    public function property(string $name): self
    {
        return $this->properties[$name] ??= new self;
    }

    public function hasProperty(string $name): bool
    {
        return isset($this->properties[$name]);
    }

    public function assignId(?string $id): self
    {
        $this->id = $id;

        return $this;
    }

    /**
     * @param  array<string, mixed>|null  $mock
     */
    public function assignMock(?array $mock): self
    {
        $this->mock = $mock;

        return $this;
    }

    public function guard(): PatchGuard
    {
        return $this->guard;
    }

    public function freeze(): SchemaObject
    {
        $data = $this->guard->resolved();

        if ($this->properties !== []) {
            $properties = [];
            foreach ($this->properties as $name => $draft) {
                $properties[$name] = $draft->freeze()->toArray();
            }
            $data['properties'] = $properties;
        }

        $docuccino = new NodeExtension(
            id: $this->id,
            provenance: $this->guard->provenance(),
            mock: $this->mock,
        );

        if (! $docuccino->isEmpty()) {
            $data['x-docuccino'] = $docuccino->toArray();
        }

        return new SchemaObject($data);
    }
}
