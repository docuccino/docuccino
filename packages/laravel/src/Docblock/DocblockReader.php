<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Docblock;

use PHPStan\PhpDocParser\Ast\PhpDoc\PhpDocNode;
use PHPStan\PhpDocParser\Ast\PhpDoc\PhpDocTextNode;
use PHPStan\PhpDocParser\Lexer\Lexer;
use PHPStan\PhpDocParser\Parser\ConstExprParser;
use PHPStan\PhpDocParser\Parser\PhpDocParser;
use PHPStan\PhpDocParser\Parser\TokenIterator;
use PHPStan\PhpDocParser\Parser\TypeParser;
use PHPStan\PhpDocParser\ParserConfig;
use Throwable;

/**
 * Splits a docblock's leading prose into an OAS `summary` (first paragraph) and `description`
 * (the remainder) using phpstan/phpdoc-parser — the one type/doc grammar used everywhere.
 */
final class DocblockReader
{
    private readonly Lexer $lexer;

    private readonly PhpDocParser $parser;

    public function __construct()
    {
        $config = new ParserConfig([]);
        $this->lexer = new Lexer($config);
        $constExprParser = new ConstExprParser($config);
        $typeParser = new TypeParser($config, $constExprParser);
        $this->parser = new PhpDocParser($config, $typeParser, $constExprParser);
    }

    /**
     * @return array{summary: ?string, description: ?string}
     */
    public function read(?string $docComment): array
    {
        $node = $this->parse($docComment);
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

    private function parse(?string $docComment): ?PhpDocNode
    {
        if ($docComment === null || $docComment === '') {
            return null;
        }

        try {
            return $this->parser->parse(new TokenIterator($this->lexer->tokenize($docComment)));
        } catch (Throwable) {
            return null;
        }
    }
}
