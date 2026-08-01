<?php

declare(strict_types=1);

namespace Docuccino\Core\Draft;

use Docuccino\Core\Document\DocuccinoExtension;
use Docuccino\Core\Document\Operation;
use Docuccino\Core\Patch\Contribution;
use Docuccino\Core\Patch\PatchGuard;
use Docuccino\Core\Patch\PatchResult;

/**
 * The mutable operation builder (design §5/§7). Scalar fields go through the guard; parameters
 * merge by `(in, name)` and responses by status, each a nested draft with its own guard — so
 * a targeted patch never wipes inferred siblings. {@see freeze()} produces the immutable
 * Phase 1a {@see Operation}, provenance assembled into every node's `x-docuccino`.
 */
final class OperationDraft
{
    private readonly PatchGuard $guard;

    /**
     * @var array<string, ParameterDraft>
     */
    private array $parameters = [];

    /**
     * @var array<string, ResponseDraft>
     */
    private array $responses = [];

    private ?string $id = null;

    public function __construct()
    {
        $this->guard = new PatchGuard;
    }

    public function setOperationId(?string $value, Contribution $by): PatchResult
    {
        return $this->guard->apply('operationId', $value, $by);
    }

    public function setSummary(?string $value, Contribution $by): PatchResult
    {
        return $this->guard->apply('summary', $value, $by);
    }

    public function setDescription(?string $value, Contribution $by): PatchResult
    {
        return $this->guard->apply('description', $value, $by);
    }

    public function setDeprecated(?bool $value, Contribution $by): PatchResult
    {
        return $this->guard->apply('deprecated', $value, $by);
    }

    /**
     * @param  list<string>|null  $tags
     */
    public function setTags(?array $tags, Contribution $by): PatchResult
    {
        return $this->guard->apply('tags', $tags, $by);
    }

    /**
     * @param  list<array<string, mixed>>|null  $security
     */
    public function setSecurity(?array $security, Contribution $by): PatchResult
    {
        return $this->guard->apply('security', $security, $by);
    }

    public function set(string $field, mixed $value, Contribution $by): PatchResult
    {
        return $this->guard->apply($field, $value, $by);
    }

    public function parameter(string $in, string $name): ParameterDraft
    {
        $key = $in.':'.$name;

        return $this->parameters[$key] ??= new ParameterDraft($in, $name);
    }

    public function hasParameter(string $in, string $name): bool
    {
        return isset($this->parameters[$in.':'.$name]);
    }

    public function removeParameter(string $in, string $name): void
    {
        unset($this->parameters[$in.':'.$name]);
    }

    public function response(string $status): ResponseDraft
    {
        return $this->responses[$status] ??= new ResponseDraft($status);
    }

    public function hasResponse(string $status): bool
    {
        return isset($this->responses[$status]);
    }

    public function removeResponse(string $status): void
    {
        unset($this->responses[$status]);
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

    public function freeze(): Operation
    {
        $resolved = $this->guard->resolved();

        $operationId = self::stringOrNull($resolved['operationId'] ?? null);
        $summary = self::stringOrNull($resolved['summary'] ?? null);
        $description = self::stringOrNull($resolved['description'] ?? null);
        $deprecated = self::boolOrNull($resolved['deprecated'] ?? null);
        $tags = self::stringList($resolved['tags'] ?? null);
        $security = self::securityList($resolved['security'] ?? null);

        unset(
            $resolved['operationId'], $resolved['summary'], $resolved['description'],
            $resolved['deprecated'], $resolved['tags'], $resolved['security'],
        );

        $parameters = [];
        foreach ($this->parameters as $draft) {
            $parameters[] = $draft->freeze();
        }

        $responses = [];
        foreach ($this->responses as $status => $draft) {
            $responses[$status] = $draft->freeze();
        }

        $docuccino = new DocuccinoExtension(id: $this->id, provenance: $this->guard->provenance());

        return new Operation(
            operationId: $operationId,
            summary: $summary,
            description: $description,
            tags: $tags,
            deprecated: $deprecated,
            parameters: $parameters,
            responses: $responses,
            security: $security,
            docuccino: $docuccino->isEmpty() ? null : $docuccino,
            rest: $resolved,
        );
    }

    private static function stringOrNull(mixed $value): ?string
    {
        return is_string($value) ? $value : null;
    }

    private static function boolOrNull(mixed $value): ?bool
    {
        return is_bool($value) ? $value : null;
    }

    /**
     * @return list<string>
     */
    private static function stringList(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        $out = [];
        foreach ($value as $item) {
            if (is_string($item)) {
                $out[] = $item;
            }
        }

        return $out;
    }

    /**
     * @return list<array<string, mixed>>|null
     */
    private static function securityList(mixed $value): ?array
    {
        if (! is_array($value)) {
            return null;
        }

        $out = [];
        foreach ($value as $item) {
            if (is_array($item)) {
                /** @var array<string, mixed> $item */
                $out[] = $item;
            }
        }

        return $out;
    }
}
