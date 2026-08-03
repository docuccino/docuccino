<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Integrations\LaravelActions;

/**
 * The single entry point for the `lorisleiva/laravel-actions` integration (Phase 5c). Registered
 * behind a `trait_exists` guard on the package's `AsController` trait, so docuccino/laravel never
 * hard-requires it. The route-method remapping (an invokable action → its `asController()`/`handle()`
 * method) is applied earlier, in the route reflector via {@see LaravelAction}; these extensions then
 * document the resolved method's request (`rules()`) and its `authorize()` 403.
 */
final class LaravelActionsIntegration
{
    /**
     * The class-presence probe is injectable so the gated-off branch is testable where the package
     * is in fact present.
     *
     * @param  (callable(string): bool)|null  $probe
     */
    public static function installed(?callable $probe = null): bool
    {
        $probe ??= static fn (string $class): bool => trait_exists($class);

        return $probe(LaravelAction::CONTROLLER_TRAIT);
    }

    /**
     * @return list<class-string>
     */
    public static function extensions(): array
    {
        return [
            ActionValidationExtension::class,
            ActionAuthorizeResponsesExtension::class,
        ];
    }
}
