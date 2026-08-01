<?php

declare(strict_types=1);

namespace Docuccino\Core\Draft;

use Docuccino\Core\Document\DocuccinoExtension;
use Docuccino\Core\Document\ResponseObject;
use Docuccino\Core\Patch\Contribution;
use Docuccino\Core\Patch\PatchGuard;
use Docuccino\Core\Patch\PatchResult;

/**
 * A mutable OAS response builder, keyed in its parent operation by status. Description and
 * `$ref` are guarded; response content merges by media type, each media type owning one
 * {@see SchemaDraft} (design §7 collection-merge).
 */
final class ResponseDraft
{
    private readonly PatchGuard $guard;

    /**
     * @var array<string, SchemaDraft>
     */
    private array $content = [];

    private ?string $id = null;

    public function __construct(
        public readonly string $status,
    ) {
        $this->guard = new PatchGuard;
    }

    public function setDescription(?string $value, Contribution $by): PatchResult
    {
        return $this->guard->apply('description', $value, $by);
    }

    public function setRef(?string $value, Contribution $by): PatchResult
    {
        return $this->guard->apply('$ref', $value, $by);
    }

    public function set(string $field, mixed $value, Contribution $by): PatchResult
    {
        return $this->guard->apply($field, $value, $by);
    }

    public function content(string $mediaType): SchemaDraft
    {
        return $this->content[$mediaType] ??= new SchemaDraft;
    }

    public function hasContent(string $mediaType): bool
    {
        return isset($this->content[$mediaType]);
    }

    public function guard(): PatchGuard
    {
        return $this->guard;
    }

    public function withId(?string $id): self
    {
        $this->id = $id;

        return $this;
    }

    public function freeze(): ResponseObject
    {
        $resolved = $this->guard->resolved();

        $ref = self::stringOrNull($resolved['$ref'] ?? null);
        $description = self::stringOrNull($resolved['description'] ?? null);

        $headers = null;
        if (isset($resolved['headers']) && is_array($resolved['headers'])) {
            /** @var array<string, mixed> $headers */
            $headers = $resolved['headers'];
        }

        unset($resolved['$ref'], $resolved['description'], $resolved['headers']);

        $content = null;
        if ($this->content !== []) {
            $content = [];
            foreach ($this->content as $mediaType => $schema) {
                $content[$mediaType] = ['schema' => $schema->freeze()->toArray()];
            }
        }

        $docuccino = new DocuccinoExtension(id: $this->id, provenance: $this->guard->provenance());

        return new ResponseObject(
            ref: $ref,
            description: $description,
            headers: $headers,
            content: $content,
            docuccino: $docuccino->isEmpty() ? null : $docuccino,
            rest: $resolved,
        );
    }

    private static function stringOrNull(mixed $value): ?string
    {
        return is_string($value) ? $value : null;
    }
}
