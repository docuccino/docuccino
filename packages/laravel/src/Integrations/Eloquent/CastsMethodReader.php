<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Integrations\Eloquent;

use PhpParser\Node\Expr\Array_;
use PhpParser\Node\Expr\ClassConstFetch;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;
use PhpParser\Node\Scalar\String_;
use PhpParser\Node\Stmt\ClassMethod;
use PhpParser\Node\Stmt\Return_;
use PhpParser\NodeFinder;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\NameResolver;
use PhpParser\ParserFactory;
use Throwable;

/**
 * Recovers the cast map a model declares via the Laravel 11+ `casts()` METHOD (the default skeleton
 * style), which {@see \ReflectionClass::getDefaultProperties()} cannot see. Reads the method's literal
 * `return [...]` array statically — column => `'datetime'`/`'array'`/… string literal, or
 * `Status::class` enum-cast (resolved to its FQCN via php-parser's NameResolver). Anything the method
 * does not express as a flat literal array (a conditional, a computed value) is skipped; a parse
 * failure yields an empty map, so the model simply falls back to its `$casts` property — never an error.
 */
final class CastsMethodReader
{
    /**
     * @return array<string, string>
     */
    public function read(?string $file): array
    {
        if ($file === null || ! is_file($file)) {
            return [];
        }

        try {
            $code = file_get_contents($file);
            if ($code === false) {
                return [];
            }

            $ast = (new ParserFactory)->createForNewestSupportedVersion()->parse($code);
            if ($ast === null) {
                return [];
            }

            $ast = (new NodeTraverser(new NameResolver))->traverse($ast);

            $method = (new NodeFinder)->findFirst(
                $ast,
                static fn (object $node): bool => $node instanceof ClassMethod && $node->name->toString() === 'casts',
            );

            return $method instanceof ClassMethod ? $this->fromMethod($method) : [];
        } catch (Throwable) {
            return [];
        }
    }

    /**
     * @return array<string, string>
     */
    private function fromMethod(ClassMethod $method): array
    {
        $return = (new NodeFinder)->findFirst(
            $method->stmts ?? [],
            static fn (object $node): bool => $node instanceof Return_ && $node->expr instanceof Array_,
        );

        if (! $return instanceof Return_ || ! $return->expr instanceof Array_) {
            return [];
        }

        $casts = [];
        foreach ($return->expr->items as $item) {
            if (! $item->key instanceof String_) {
                continue;
            }

            $cast = $this->castValue($item->value);
            if ($cast !== null) {
                $casts[$item->key->value] = $cast;
            }
        }

        return $casts;
    }

    private function castValue(object $value): ?string
    {
        if ($value instanceof String_) {
            return $value->value;
        }

        // `Status::class` → the NameResolver has made the class a fully-qualified Name.
        if ($value instanceof ClassConstFetch
            && $value->name instanceof Identifier
            && $value->name->toString() === 'class'
            && $value->class instanceof Name
        ) {
            return $value->class->toString();
        }

        return null;
    }
}
