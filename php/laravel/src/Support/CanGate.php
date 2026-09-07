<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Support;

/**
 * A route's authorization middleware, read the way Laravel's own `Route::can()` writes it: the ability,
 * then the comma-separated model arguments. `->can('view', Widget::class)` reaches the route as
 * `can:view,App\Models\Widget`, and `->can('view')` as `can:view` with no arguments at all.
 *
 * The `can` alias is one of two spellings. `Authorize::using('view', Widget::class)` — the class-name
 * style the framework ships for middleware that takes arguments — renders the same gate as
 * `Illuminate\Auth\Middleware\Authorize:view,App\Models\Widget`, with no alias in it at all, so the
 * prefix list lives here and every reader asks this class rather than spelling one of them out again.
 *
 * Pure, so the middleware grammar is dataset-testable. Null for anything that is not a gate.
 */
final readonly class CanGate
{
    /**
     * Every spelling of the authorization middleware, longest first so no short alias shadows another.
     *
     * @var list<string>
     */
    private const array PREFIXES = ['Illuminate\\Auth\\Middleware\\Authorize:', 'can:'];

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
        $prefix = self::prefix($middleware);
        if ($prefix === null) {
            return null;
        }

        $parts = explode(',', substr($middleware, strlen($prefix)));
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

    /**
     * Whether a middleware string IS an authorization gate, however it is spelled — the question a
     * reader that only needs the signal asks, and the reason no caller repeats a prefix.
     */
    public static function matches(string $middleware): bool
    {
        return self::prefix($middleware) !== null;
    }

    private static function prefix(string $middleware): ?string
    {
        foreach (self::PREFIXES as $prefix) {
            if (str_starts_with($middleware, $prefix)) {
                return $prefix;
            }
        }

        return null;
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
