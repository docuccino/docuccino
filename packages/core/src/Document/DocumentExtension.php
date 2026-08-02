<?php

declare(strict_types=1);

namespace Docuccino\Core\Document;

use Docuccino\Core\Diagnostics\Diagnostic;
use Docuccino\Core\Support\Hydrate;

/**
 * Document-level `x-docuccino` member: identity, generator, content tree and diagnostics.
 *
 * @internal
 */
final readonly class DocumentExtension
{
    /**
     * @param  array<string, mixed>|null  $content
     * @param  list<Diagnostic>  $diagnostics
     * @param  array<string, mixed>  $rest
     */
    public function __construct(
        public ?DocumentMeta $document = null,
        public ?Generator $generator = null,
        public ?array $content = null,
        public array $diagnostics = [],
        public array $rest = [],
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        $document = Hydrate::objectOrNull($data['document'] ?? null, DocumentMeta::fromArray(...));
        unset($data['document']);

        $generator = Hydrate::objectOrNull($data['generator'] ?? null, Generator::fromArray(...));
        unset($data['generator']);

        $content = null;
        if (isset($data['content']) && is_array($data['content'])) {
            /** @var array<string, mixed> $content */
            $content = $data['content'];
        }
        unset($data['content']);

        $diagnostics = Hydrate::listOf($data['diagnostics'] ?? null, Diagnostic::fromArray(...));
        unset($data['diagnostics']);

        return new self(
            document: $document,
            generator: $generator,
            content: $content,
            diagnostics: $diagnostics,
            rest: $data,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $out = [];

        if ($this->document !== null) {
            $out['document'] = $this->document->toArray();
        }

        if ($this->generator !== null) {
            $out['generator'] = $this->generator->toArray();
        }

        if ($this->content !== null) {
            $out['content'] = $this->content;
        }

        if ($this->diagnostics !== []) {
            $out['diagnostics'] = array_map(
                static fn (Diagnostic $diagnostic): array => $diagnostic->toArray(),
                $this->diagnostics,
            );
        }

        return $out + $this->rest;
    }

    public function withDocument(DocumentMeta $document): self
    {
        return new self($document, $this->generator, $this->content, $this->diagnostics, $this->rest);
    }
}
