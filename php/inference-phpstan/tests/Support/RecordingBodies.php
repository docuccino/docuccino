<?php

declare(strict_types=1);

namespace Docuccino\Inference\PhpStan\Tests\Support;

use Docuccino\Inference\PhpStan\Throwing\ClassBodies;
use PhpParser\Node;

/**
 * A {@see ClassBodies} that answers exactly like the one it wraps and remembers which bodies were asked to
 * fold something — the observable for "this body was never read", which a return value alone cannot show.
 */
final class RecordingBodies implements ClassBodies
{
    /** @var list<string> the method names whose scope an expression was folded in, in order */
    public array $folded = [];

    public function __construct(private readonly ClassBodies $inner) {}

    public function methods(string $file, string $class): array
    {
        return $this->inner->methods($file, $class);
    }

    public function foldInt(string $file, string $class, string $method, Node\Expr $expr): ?int
    {
        $this->folded[] = $method;

        return $this->inner->foldInt($file, $class, $method, $expr);
    }
}
