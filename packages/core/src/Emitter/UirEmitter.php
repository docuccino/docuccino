<?php

declare(strict_types=1);

namespace Docuccino\Core\Emitter;

use Docuccino\Core\Canonical\Canonicalizer;
use Docuccino\Core\Canonical\CanonicalJsonSerializer;
use Docuccino\Core\Document\UirDocument;

/**
 * Emits a {@see UirDocument} as canonical UIR JSON: the document is canonicalised
 * (member order, sorted keys, method/parameter order) and serialised deterministically.
 */
final readonly class UirEmitter
{
    public function __construct(
        private Canonicalizer $canonicalizer = new Canonicalizer,
        private CanonicalJsonSerializer $serializer = new CanonicalJsonSerializer,
    ) {}

    public function format(): string
    {
        return 'uir';
    }

    public function emit(UirDocument $document): string
    {
        return $this->emitArray($document->toArray());
    }

    /**
     * @param  array<string, mixed>  $document
     */
    public function emitArray(array $document): string
    {
        return $this->serializer->serialize($this->canonicalizer->canonicalize($document));
    }
}
