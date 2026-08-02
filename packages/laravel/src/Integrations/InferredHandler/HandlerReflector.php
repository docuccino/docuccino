<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Integrations\InferredHandler;

use Closure;
use Illuminate\Contracts\Debug\ExceptionHandler;
use ReflectionFunction;
use ReflectionNamedType;
use ReflectionObject;
use Throwable;

/**
 * Reflects the BOOTED app's exception handler for the render callbacks it registered
 * (`$exceptions->render(…)` → `Illuminate\Foundation\Exceptions\Handler::$renderCallbacks`) —
 * catching provider- and package-registered handlers a static AST scan would miss (design §6
 * inferred-handler tier). The result is memoised: the handler is reflected once per build, and each
 * callback's file+line + first-parameter type feed the engine's closure-by-line analysis. Every
 * step is defensive — an unexpected handler shape yields no callbacks rather than a failed build.
 */
final class HandlerReflector
{
    /** @var list<RenderCallback>|null */
    private ?array $callbacks = null;

    public function __construct(private readonly ExceptionHandler $handler) {}

    /**
     * The registered render callbacks in registration order (the order Laravel itself matches them
     * in — first whose first-parameter type the exception `is_a` wins).
     *
     * @return list<RenderCallback>
     */
    public function renderCallbacks(): array
    {
        return $this->callbacks ??= $this->discover();
    }

    /**
     * @return list<RenderCallback>
     */
    private function discover(): array
    {
        try {
            $reflection = new ReflectionObject($this->handler);
            if (! $reflection->hasProperty('renderCallbacks')) {
                return [];
            }

            $value = $reflection->getProperty('renderCallbacks')->getValue($this->handler);
            if (! is_array($value)) {
                return [];
            }

            $callbacks = [];
            foreach ($value as $callback) {
                $resolved = $callback instanceof Closure ? $this->resolve($callback) : null;
                if ($resolved !== null) {
                    $callbacks[] = $resolved;
                }
            }

            return $callbacks;
        } catch (Throwable) {
            return [];
        }
    }

    private function resolve(Closure $callback): ?RenderCallback
    {
        $function = new ReflectionFunction($callback);
        $parameters = $function->getParameters();
        $file = $function->getFileName();

        if ($parameters === [] || $file === false) {
            return null;
        }

        $type = $parameters[0]->getType();
        $line = $function->getStartLine();
        if (! $type instanceof ReflectionNamedType || $type->isBuiltin() || $line === false) {
            return null;
        }

        return new RenderCallback(
            $file,
            $line,
            $parameters[0]->getName(),
            ltrim($type->getName(), '\\'),
        );
    }
}
