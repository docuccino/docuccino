<?php

declare(strict_types=1);

namespace Docuccino\Inference\PhpStan\Metadata;

use PHPStan\PhpDocParser\Ast\PhpDoc\PhpDocTextNode;
use PHPStan\PhpDocParser\Lexer\Lexer;
use PHPStan\PhpDocParser\Parser\ConstExprParser;
use PHPStan\PhpDocParser\Parser\PhpDocParser;
use PHPStan\PhpDocParser\Parser\TokenIterator;
use PHPStan\PhpDocParser\Parser\TypeParser;
use PHPStan\PhpDocParser\ParserConfig;
use Throwable;

/**
 * Extracts prose + `@example` from a raw docblock using phpstan/phpdoc-parser —
 * one type grammar everywhere (design). Framework-agnostic; touches no PHPStan
 * analysis internals.
 */
final class DocBlockReader
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

    /** The leading prose (summary + description), or null when absent. */
    public function summary(?string $docComment): ?string
    {
        if ($docComment === null || $docComment === '') {
            return null;
        }

        try {
            $node = $this->parser->parse(new TokenIterator($this->lexer->tokenize($docComment)));
        } catch (Throwable) {
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
        if ($docComment === null || $docComment === '') {
            return null;
        }

        try {
            $node = $this->parser->parse(new TokenIterator($this->lexer->tokenize($docComment)));
        } catch (Throwable) {
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
