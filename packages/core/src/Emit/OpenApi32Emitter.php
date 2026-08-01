<?php

declare(strict_types=1);

namespace Docuccino\Core\Emit;

use Docuccino\Core\Canonical\Canonicalizer;
use Docuccino\Core\Canonical\CanonicalJsonSerializer;
use Docuccino\Core\Document\UirDocument;

/**
 * Emits a {@see UirDocument} as pure OpenAPI 3.2 (JSON or YAML): every `x-uir` member is
 * stripped, along with the UIR-only top-level `$schema` and `uir` version. Options may re-emit
 * ids as flat `x-uir-id` members and map schema mock hints to a faker member; provenance is
 * always dropped. The content layer (`x-uir.content`) has nowhere to live in OAS and is
 * dropped — `info.description`/tag descriptions already sit in standard fields.
 *
 * Output flows through the shared canonical serializer, so 3.2 emission is byte-deterministic
 * and, with default options, round-trips losslessly against the x-uir-stripped UIR.
 */
final readonly class OpenApi32Emitter
{
    public function __construct(
        private Canonicalizer $canonicalizer = new Canonicalizer,
        private CanonicalJsonSerializer $serializer = new CanonicalJsonSerializer,
        private YamlSerializer $yaml = new YamlSerializer,
    ) {}

    public function format(): string
    {
        return 'openapi-3.2';
    }

    public function emit(UirDocument $document, EmitOptions $options = new EmitOptions): string
    {
        $canonical = $this->canonicalizer->canonicalize($this->toOpenApiArray($document, $options));

        return $options->yaml
            ? $this->yaml->serialize($canonical)
            : $this->serializer->serialize($canonical);
    }

    /**
     * The pure OpenAPI 3.2 array (pre-canonicalisation), reused by the 3.1 downlevel emitter.
     *
     * @return array<string, mixed>
     */
    public function toOpenApiArray(UirDocument $document, EmitOptions $options = new EmitOptions): array
    {
        $array = $document->toArray();

        unset($array['$schema'], $array['uir']);

        /** @var array<string, mixed> $stripped */
        $stripped = $this->strip($array, $options);

        return $stripped;
    }

    private function strip(mixed $node, EmitOptions $options): mixed
    {
        if (! is_array($node)) {
            return $node;
        }

        if (array_is_list($node)) {
            return array_map(fn (mixed $item): mixed => $this->strip($item, $options), $node);
        }

        $xUir = $node['x-uir'] ?? null;
        unset($node['x-uir']);

        $out = [];
        foreach ($node as $key => $value) {
            $out[(string) $key] = str_starts_with((string) $key, 'x-')
                ? $value
                : $this->strip($value, $options);
        }

        if (is_array($xUir)) {
            $this->projectXUir($out, $xUir, $options);
        }

        return $out;
    }

    /**
     * @param  array<string, mixed>  $out
     * @param  array<mixed, mixed>  $xUir
     */
    private function projectXUir(array &$out, array $xUir, EmitOptions $options): void
    {
        if ($options->keepIds && isset($xUir['id']) && is_string($xUir['id'])) {
            $out['x-uir-id'] = $xUir['id'];
        }

        if ($options->mockFakerKey !== null
            && isset($xUir['mock']) && is_array($xUir['mock'])
            && isset($xUir['mock']['faker']) && is_string($xUir['mock']['faker'])
        ) {
            $out[$options->mockFakerKey] = $xUir['mock']['faker'];
        }
    }
}
