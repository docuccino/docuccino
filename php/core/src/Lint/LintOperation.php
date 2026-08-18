<?php

declare(strict_types=1);

namespace Docuccino\Core\Lint;

use Docuccino\Core\Document\PathItem;
use Docuccino\Core\Provenance\Source;

/**
 * One operation as the document lints see it: the `METHOD /path` signature their messages name, the
 * node itself, and whatever source the provenance trail recorded for it.
 *
 * {@see all()} sorts by signature, so where a finding lands never depends on the order its route was
 * met, and adding a route never moves another's finding.
 *
 * @internal
 */
final readonly class LintOperation
{
    /**
     * @param  array<string, mixed>  $operation
     */
    public function __construct(
        public string $signature,
        public array $operation,
    ) {}

    /**
     * Every operation in an assembled draft, in signature order.
     *
     * @param  array<string, mixed>  $document
     * @return list<self>
     */
    public static function all(array $document): array
    {
        $paths = $document['paths'] ?? null;
        if (! is_array($paths)) {
            return [];
        }

        $operations = [];
        foreach ($paths as $path => $item) {
            if (! is_array($item)) {
                continue;
            }

            foreach (PathItem::METHODS as $method) {
                $operation = $item[$method] ?? null;
                if (is_array($operation)) {
                    /** @var array<string, mixed> $operation */
                    $operations[] = new self(strtoupper($method).' '.$path, $operation);
                }
            }
        }

        usort($operations, static fn (self $a, self $b): int => strcmp($a->signature, $b->signature));

        return $operations;
    }

    public function operationId(): ?string
    {
        $operationId = $this->operation['operationId'] ?? null;

        return is_string($operationId) && $operationId !== '' ? $operationId : null;
    }

    /**
     * The tags the operation carries, ignoring anything that isn't a non-empty string.
     *
     * @return list<string>
     */
    public function tags(): array
    {
        $tags = $this->operation['tags'] ?? null;
        if (! is_array($tags)) {
            return [];
        }

        $names = [];
        foreach ($tags as $tag) {
            if (is_string($tag) && $tag !== '') {
                $names[] = $tag;
            }
        }

        return $names;
    }

    /**
     * Where the operation was written, from the first provenance record that recorded it. Provenance
     * is stripped at emit rather than before transformers run, so this answers the same on every
     * `--provenance` level; a closure route nothing traced simply has none.
     */
    public function source(): ?Source
    {
        $extension = $this->operation['x-docuccino'] ?? null;
        $records = is_array($extension) ? ($extension['provenance'] ?? null) : null;
        if (! is_array($records)) {
            return null;
        }

        foreach ($records as $record) {
            $source = is_array($record) ? ($record['source'] ?? null) : null;
            if (is_array($source) && is_string($source['file'] ?? null)) {
                /** @var array<string, mixed> $source */
                return Source::fromArray($source);
            }
        }

        return null;
    }
}
