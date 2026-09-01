<?php

declare(strict_types=1);

namespace Docuccino\Inference\PhpStan\Throwing;

use PhpParser\Node;
use ReflectionClass;
use ReflectionMethod;
use ReflectionParameter;

/**
 * The status an `HttpException` subclass pins on itself.
 *
 * `HttpException` takes its status as a constructor argument, so a subclass that fixes one — which is how
 * an application says "this exception IS a 409" — states it in its own `parent::__construct()` call,
 * somewhere no name-keyed table can see. Reading it is what keeps a domain exception off the 500 a lookup
 * miss publishes, and 500 is the one answer that is never merely vague: it names a failure the server does
 * not have, on an endpoint whose only rejection is a 409.
 *
 * Two shapes pin a status, and the same applications carry both: a constant reaching the parent call
 * (`parent::__construct(422, …)`), and a constructor parameter with a constant default forwarded to it
 * (`__construct(array $errors, int $statusCode = 422)`, the static-factory idiom). The second is pinned
 * only where the class controls every construction — a PRIVATE constructor, no trait that could construct
 * out of sight, and no in-class `new self(...)` writing that slot — because a caller free to pass another
 * value makes the default a guess rather than a fact. Where the class pins nothing but forwards a
 * parameter, {@see statusParameter()} names the slot so a visible `throw new X(423, …)` can still be
 * folded at its site.
 *
 * Anything else answers null, which means "this class knows a status this build does not" — a different
 * claim from the 500 that means "no HTTP status at all", and the reason the two are not one return value.
 *
 * A class inherits its parent's answer along with its constructor, so the walk is up the hierarchy: a
 * literal the parent pins is the subclass's status too, and the slot the parent forwards is the slot the
 * subclass's own `parent::__construct()` writes into. `HttpException` itself is the base case — argument 0
 * of its constructor IS the status.
 *
 * @phpstan-type StatusPin array{status: int|null, parameter: int|null, files: list<string>}
 *
 * @internal
 */
final class HttpExceptionStatus
{
    /** Argument 0 of `HttpException::__construct` is the status. */
    private const STATUS_SLOT = 0;

    /**
     * Resolutions by FQCN. Seeded before the work so a hierarchy that cannot terminate answers rather than
     * recurses, and kept for the whole build so one exception class is read once however many routes throw
     * it.
     *
     * @var array<string, StatusPin>
     */
    private array $cache = [];

    public function __construct(private readonly ConstructorSource $constructors) {}

    public function isHttpException(string $fqcn): bool
    {
        return class_exists($fqcn) && is_a($fqcn, KnownThrowers::HTTP_EXCEPTION, true);
    }

    /** The status the class states for every one of its instances, or null when none folded. */
    public function pinned(string $fqcn): ?int
    {
        return $this->resolve($fqcn)['status'];
    }

    /**
     * The constructor slot whose argument becomes the status, for a class that forwards one rather than
     * pinning it — argument 0 where no class below `HttpException` adds a constructor and its own is what
     * runs. Null when nothing forwards one.
     */
    public function statusParameter(string $fqcn): ?int
    {
        return $this->resolve($fqcn)['parameter'];
    }

    /**
     * Files whose contents were read to answer, for the analysis's dependency set: an exception class that
     * changes the status it sets must rebuild every route that throws it, and a warm build that missed it
     * would publish a status a cold one does not. The whole hierarchy is recorded, not just the class that
     * happens to declare the constructor today — adding one lower down changes the answer.
     *
     * @return list<string>
     */
    public function filesFor(string $fqcn): array
    {
        return $this->resolve($fqcn)['files'];
    }

    /**
     * @return StatusPin
     */
    private function resolve(string $fqcn): array
    {
        if (isset($this->cache[$fqcn])) {
            return $this->cache[$fqcn];
        }

        $this->cache[$fqcn] = ['status' => null, 'parameter' => null, 'files' => []];

        // Spelled out rather than asked of `isHttpException()`, which answers the same question: the
        // `class_exists()` is what makes the name a class the reflection below may be handed.
        if (! class_exists($fqcn) || ! is_a($fqcn, KnownThrowers::HTTP_EXCEPTION, true)) {
            return $this->cache[$fqcn];
        }

        return $this->cache[$fqcn] = $this->forClass(new ReflectionClass($fqcn));
    }

    /**
     * @param  ReflectionClass<object>  $class
     * @return StatusPin
     */
    private function forClass(ReflectionClass $class): array
    {
        $file = $class->getFileName();
        $files = $file === false ? [] : [$file];

        if ($class->getName() === KnownThrowers::HTTP_EXCEPTION) {
            return ['status' => null, 'parameter' => self::STATUS_SLOT, 'files' => $files];
        }

        $parent = $class->getParentClass();
        if ($parent === false) {
            return ['status' => null, 'parameter' => null, 'files' => $files];
        }

        $inherited = $this->resolve($parent->getName());
        $files = [...$files, ...$inherited['files']];

        $constructor = $class->getConstructor();

        // No constructor of its own, so the parent's is what runs — and its answer is this class's.
        if ($constructor === null || $constructor->getDeclaringClass()->getName() !== $class->getName()) {
            return ['status' => $inherited['status'], 'parameter' => $inherited['parameter'], 'files' => $files];
        }

        return $this->readConstructor($class, $constructor, $parent, $inherited, $files);
    }

    /**
     * What one class's own constructor does with the status the parent's takes.
     *
     * @param  ReflectionClass<object>  $class
     * @param  ReflectionClass<object>  $parent
     * @param  StatusPin  $inherited
     * @param  list<string>  $files
     * @return StatusPin
     */
    private function readConstructor(
        ReflectionClass $class,
        ReflectionMethod $constructor,
        ReflectionClass $parent,
        array $inherited,
        array $files,
    ): array {
        $none = ['status' => null, 'parameter' => null, 'files' => $files];

        $file = $class->getFileName();
        if ($file === false) {
            return $none;
        }

        $body = $this->constructors->methods($file, $class->getName())['__construct'] ?? null;
        if ($body === null) {
            return $none;
        }

        $call = StatusForwarding::parentCall($body);
        if ($call === null) {
            return $none;
        }

        // A literal the parent pins is one no subclass can move: it reaches the parent's own
        // `parent::__construct()` call, and this constructor only chooses the message beside it.
        if ($inherited['status'] !== null) {
            return ['status' => $inherited['status'], 'parameter' => null, 'files' => $files];
        }

        $slot = $inherited['parameter'];
        if ($slot === null) {
            return $none;
        }

        $argument = StatusForwarding::argumentAt($call, $slot, self::parameterNames($parent->getConstructor()));
        if ($argument === null) {
            return $none;
        }

        $folded = $this->constructors->foldInt($file, $class->getName(), $argument);
        if ($folded !== null) {
            return ['status' => $folded, 'parameter' => null, 'files' => $files];
        }

        if (! $argument instanceof Node\Expr\Variable || ! is_string($argument->name)) {
            return $none;
        }

        $index = array_search($argument->name, self::parameterNames($constructor), true);
        if (! is_int($index)) {
            return $none;
        }

        return [
            'status' => $this->forwardedDefault($class, $constructor, $index, $file),
            'parameter' => $index,
            'files' => $files,
        ];
    }

    /**
     * The default of a forwarded status parameter, where that default is the only value any instance can
     * have been built with: a private constructor — so every construction is in this class — with no
     * `new self(...)` writing the slot. A public or protected one leaves callers this build cannot see, and
     * publishing the default for them would state a status the code does not.
     *
     * A class that uses a trait declines for the same reason at one remove: a trait's methods are written in
     * another file, so a `new self(...)` there is one this read never sees.
     *
     * @param  ReflectionClass<object>  $class
     */
    private function forwardedDefault(ReflectionClass $class, ReflectionMethod $constructor, int $index, string $file): ?int
    {
        if (! $constructor->isPrivate() || $class->getTraitNames() !== []) {
            return null;
        }

        $parameter = $constructor->getParameters()[$index] ?? null;
        $default = $parameter?->isDefaultValueAvailable() === true ? $parameter->getDefaultValue() : null;
        if (! is_int($default)) {
            return null;
        }

        // Every method of the class, its constructor included — a private constructor is reachable from all
        // of them and from nowhere else.
        $names = self::parameterNames($constructor);
        foreach ($this->constructors->methods($file, $class->getName()) as $statements) {
            if (StatusForwarding::writesSlot($statements, $class->getName(), $index, $names)) {
                return null;
            }
        }

        return $default;
    }

    /**
     * @return list<string>
     */
    private static function parameterNames(?ReflectionMethod $method): array
    {
        return $method === null ? [] : array_map(
            static fn (ReflectionParameter $parameter): string => $parameter->getName(),
            $method->getParameters(),
        );
    }
}
