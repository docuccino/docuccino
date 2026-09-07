<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Support;

/**
 * A route's `can:` middleware, read the way Laravel's own `Route::can()` writes it: the ability, then
 * the comma-separated model arguments. `->can('view', Widget::class)` reaches the route as
 * `can:view,App\Models\Widget`, and `->can('view')` as `can:view` with no arguments at all.
 *
 * Pure, so the middleware grammar is dataset-testable. Null for anything that is not a `can:` gate.
 */
final readonly class CanGate
{
    private const string PREFIX = 'can:';

    /**
     * @param  list<string>  $arguments  each either a class name (Laravel says so when it contains a
     *                                   namespace separator) or a route-parameter name
     */
    private function __construct(
        public string $ability,
        public array $arguments,
    ) {}

    public static function parse(string $middleware): ?self
    {
        if (! str_starts_with($middleware, self::PREFIX)) {
            return null;
        }

        $parts = explode(',', substr($middleware, strlen(self::PREFIX)));
        $ability = trim($parts[0]);
        if ($ability === '') {
            return null;
        }

        $arguments = array_values(array_filter(
            array_map(trim(...), array_slice($parts, 1)),
            static fn (string $argument): bool => $argument !== '',
        ));

        return new self($ability, $arguments);
    }

    /** Whether an argument names a class — the same test the `can:` middleware itself applies. */
    public static function isClassName(string $argument): bool
    {
        return str_contains($argument, '\\');
    }

    /** The `->can()` call as the route wrote it, so a diagnostic sends its reader to the route file. */
    public function describe(): string
    {
        $arguments = array_map(
            static fn (string $argument): string => self::isClassName($argument)
                ? ltrim($argument, '\\').'::class'
                : "'".$argument."'",
            $this->arguments,
        );

        return "->can('".$this->ability."'".($arguments === [] ? '' : ', '.implode(', ', $arguments)).')';
    }
}
