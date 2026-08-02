<?php

declare(strict_types=1);

namespace Docuccino\Inference\PhpStan\Metadata;

use Docuccino\Inference\PhpStan\Support\PhpDocParserStack;
use PHPStan\PhpDocParser\Ast\PhpDoc\PhpDocTextNode;

/**
 * Extracts prose + `@example` from a raw docblock using the shared {@see PhpDocParserStack} —
 * one type grammar everywhere (design). Framework-agnostic; touches no PHPStan analysis internals.
 */
final class DocBlockReader
{
    public function __construct(
        private readonly PhpDocParserStack $stack = new PhpDocParserStack,
    ) {}

    /** The leading prose (summary + description), or null when absent. */
    public function summary(?string $docComment): ?string
    {
        $node = $this->stack->parseDocBlock($docComment);
        if ($node === null) {
            return null;
        }

        foreach ($node->children as $child) {
            if ($child instanceof PhpDocTextNode) {
                $text = trim($child->text);
                if ($text !== '') {
                    return $text;
                }
            }
        }

        return null;
    }

    /** The first `@example` value, or null. */
    public function example(?string $docComment): ?string
    {
        $node = $this->stack->parseDocBlock($docComment);
        if ($node === null) {
            return null;
        }

        foreach ($node->getTagsByName('@example') as $tag) {
            $value = trim((string) $tag->value);
            if ($value !== '') {
                return $value;
            }
        }

        return null;
    }
}
