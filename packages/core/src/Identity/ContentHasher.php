<?php

declare(strict_types=1);

namespace Docuccino\Core\Identity;

use Docuccino\Core\Canonical\Canonicalizer;
use Docuccino\Core\Canonical\CanonicalJsonSerializer;

/**
 * `contentHash` (design §1): SHA-256 (hex) over the canonical serialization of the document
 * with `x-uir.generator` and `x-uir.diagnostics` excluded, so tool upgrades and diagnostic
 * churn never dirty committed diffs.
 */
final readonly class ContentHasher
{
    public function __construct(
        private Canonicalizer $canonicalizer = new Canonicalizer,
        private CanonicalJsonSerializer $serializer = new CanonicalJsonSerializer,
    ) {}

    /**
     * @param  array<string, mixed>  $document
     */
    public function hash(array $document): string
    {
        if (isset($document['x-uir']) && is_array($document['x-uir'])) {
            unset($document['x-uir']['generator'], $document['x-uir']['diagnostics']);

            if ($document['x-uir'] === []) {
                unset($document['x-uir']);
            }
        }

        $canonical = $this->serializer->serialize($this->canonicalizer->canonicalize($document));

        return hash('sha256', $canonical);
    }
}
