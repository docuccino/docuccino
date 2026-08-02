<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Docblock;

use Docuccino\Inference\PhpStan\Support\PhpDocParserStack;
use PHPStan\PhpDocParser\Ast\PhpDoc\PhpDocTextNode;

/**
 * Splits a docblock's leading prose into an OAS `summary` (first paragraph) and `description`
 * (the remainder) using the shared {@see PhpDocParserStack} — the one type/doc grammar everywhere.
 */
final class DocblockReader
{
    public function __construct(
        private readonly PhpDocParserStack $stack = new PhpDocParserStack,
    ) {}

    /**
     * @return array{summary: ?string, description: ?string}
     */
    public function read(?string $docComment): array
    {
        $node = $this->stack->parseDocBlock($docComment);
        if ($node === null) {
            return ['summary' => null, 'description' => null];
        }

        $prose = '';
        foreach ($node->children as $child) {
            if ($child instanceof PhpDocTextNode) {
                $text = trim($child->text);
                if ($text !== '') {
                    $prose = $text;
                    break;
                }
            }
        }

        if ($prose === '') {
            return ['summary' => null, 'description' => null];
        }

        $parts = preg_split('/\R{2,}/', $prose, 2);
        $summary = trim($parts[0] ?? $prose);
        $description = isset($parts[1]) ? trim($parts[1]) : null;

        return [
            'summary' => $summary === '' ? null : $summary,
            'description' => ($description === null || $description === '') ? null : $description,
        ];
    }
}
