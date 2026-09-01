<?php

declare(strict_types=1);

namespace Docuccino\Inference\PhpStan\Throwing;

use Docuccino\Core\Inference\ArgumentSlots;
use Docuccino\Inference\PhpStan\Support\ProjectFilter;
use PhpParser\Node;
use ReflectionClass;
use ReflectionMethod;

/**
 * The status a static factory builds its exception with, for the class that states none of its own.
 *
 * A class whose factories each choose a status genuinely has no one status — `duplicatePath()` is a 422 and
 * `cannotDeleteRoot()` a 403 on the same class — so {@see HttpExceptionStatus} is right to answer null for
 * it, and the answer is one hop away: the `throw` names the factory, and the factory names the status. One
 * hop and no further; this is not constant propagation, it is reading the `new` the named factory makes.
 *
 * Every way the read could publish a status the code does not pass is a decline: a factory whose file is not
 * the project's, a body that builds the class more than once and folds to two different statuses, a slot
 * nothing can be said about ({@see ArgumentSlots::knows()}), and a factory some other class declares, where
 * `self` names that class and its constructor slots need not be this one's.
 *
 * @phpstan-type FactoryRead array{status: int|null, files: list<string>}
 *
 * @internal
 */
final class FactoryStatus
{
    /**
     * Reads by `Class::factory`, seeded before the work so nothing re-enters, and kept for the whole build
     * so one factory thrown by forty routes is read once.
     *
     * @var array<string, FactoryRead>
     */
    private array $cache = [];

    public function __construct(
        private readonly HttpExceptionStatus $statuses,
        private readonly ClassBodies $bodies,
        private readonly ProjectFilter $projectFilter,
    ) {}

    /**
     * What `$fqcn::$method()` builds the exception with. The files are the ones the answer depended on,
     * reported whether or not one folded — the read has to rebuild when the factory changes either way.
     *
     * @return FactoryRead
     */
    public function forFactory(string $fqcn, string $method): array
    {
        $key = $fqcn.'::'.$method;
        if (isset($this->cache[$key])) {
            return $this->cache[$key];
        }

        $this->cache[$key] = ['status' => null, 'files' => []];

        // The slot the class forwards its status through. Null where the class pins one — which no factory
        // can move, so the factory is never read — or where nothing of its reaches `HttpException` at all.
        $slot = $this->statuses->statusParameter($fqcn);
        if ($slot === null) {
            return $this->cache[$key];
        }

        $factory = self::factory($fqcn, $method);
        $file = $factory?->getFileName();
        if ($factory === null || $file === false || $file === null) {
            return $this->cache[$key];
        }

        $body = $this->projectFilter->isProjectFile($file)
            ? ($this->bodies->methods($file, $fqcn)[$method] ?? null)
            : null;

        return $this->cache[$key] = [
            'status' => $body === null ? null : $this->fromBody($fqcn, $method, $file, $body, $slot),
            'files' => [$file],
        ];
    }

    /**
     * The one status every construction in the body agrees on. A body that builds the class twice with two
     * statuses states neither, and one construction that would not fold takes the whole answer with it.
     *
     * @param  array<array-key, Node\Stmt>  $body
     */
    private function fromBody(string $fqcn, string $method, string $file, array $body, int $slot): ?int
    {
        $constructions = StatusForwarding::constructionsOf($body, $fqcn);
        if ($constructions === []) {
            return null;
        }

        $constructor = $this->statuses->constructorSlot($fqcn, $slot);

        $status = null;
        foreach ($constructions as $construction) {
            $one = $this->fromConstruction($construction, $fqcn, $method, $file, $slot, $constructor);
            if ($one === null || ($status !== null && $one !== $status)) {
                return null;
            }

            $status = $one;
        }

        return $status;
    }

    /**
     * @param  array{names: list<string>, default: int|null}  $constructor
     */
    private function fromConstruction(
        Node\Expr\New_ $construction,
        string $fqcn,
        string $method,
        string $file,
        int $slot,
        array $constructor,
    ): ?int {
        if ($construction->isFirstClassCallable()) {
            return null; // a callable, whose arguments are supplied somewhere this read cannot see
        }

        $slots = ArgumentSlots::of($construction->getArgs(), $constructor['names']);

        // "Absent" and "past an unreadable spread" both read as no argument, and only the first of them
        // means the parameter's default is what was passed.
        if (! $slots->knows($slot)) {
            return null;
        }

        $argument = $slots->at($slot);

        return $argument === null
            ? $constructor['default']
            : $this->bodies->foldInt($file, $fqcn, $method, $argument);
    }

    /**
     * The static factory the class itself declares, or null for anything else a `throw X::y()` could name —
     * an instance method, a name the class does not have, and one inherited from a parent, whose `new self`
     * builds the parent rather than this class.
     */
    private static function factory(string $fqcn, string $method): ?ReflectionMethod
    {
        if (! class_exists($fqcn)) {
            return null;
        }

        $class = new ReflectionClass($fqcn);
        if (! $class->hasMethod($method)) {
            return null;
        }

        $factory = $class->getMethod($method);

        return $factory->isStatic() && $factory->getDeclaringClass()->getName() === $fqcn ? $factory : null;
    }
}
