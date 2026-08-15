<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Integrations\Sanctum;

use Docuccino\Attributes\Unauthenticated;
use Docuccino\Core\Draft\OperationDraft;
use Docuccino\Core\Extensions\Context\RouteContext;
use Docuccino\Core\Extensions\Contracts\OperationExtension;
use Docuccino\Core\Extensions\Contracts\OperationPhase;
use Docuccino\Core\Patch\Contribution;
use Docuccino\Laravel\Integrations\Support\AuthGuardDrivers;
use Docuccino\Laravel\Integrations\Support\MachineDependentValue;
use Illuminate\Contracts\Config\Repository as ConfigRepository;

/**
 * Sets Sanctum security on protected operations, modelling the usual dual-auth reality: a route behind
 * `auth:sanctum` in an app that also runs `statefulApi()` really does accept both a bearer token and a
 * first-party session cookie, so it gets an OR-list `security` of `[{sanctumToken: []},
 * {sanctumStateful: []}]`, each scheme registered once into `components.securitySchemes`.
 *
 * Which modes a document exposes is per-document config (`integrations.sanctum.modes`), so audience
 * segmentation happens per document — the effective set is the route's supported modes ∩ the document's
 * allowed ones. Defers entirely when config already declares security schemes; skips `#[Unauthenticated]`.
 */
final class SanctumSecurityExtension implements OperationExtension
{
    private const DEFAULT_MODES = [SanctumDetector::TOKEN, SanctumDetector::STATEFUL];

    /** What a machine-dependent-value report names, since the scheme's own slot isn't settled yet. */
    private const PUBLISHED = "The Sanctum stateful scheme's cookie name";

    public function __construct(
        private readonly SanctumDetector $detector = new SanctumDetector,
        private readonly ?ConfigRepository $config = null,
    ) {}

    public function phase(): OperationPhase
    {
        return OperationPhase::Security;
    }

    public function handle(OperationDraft $operation, RouteContext $context): void
    {
        if ($context->document->securitySchemes() !== []) {
            return;
        }

        if ($context->attributes->has(Unauthenticated::class)) {
            return;
        }

        $modes = $this->effectiveModes($context);
        if ($modes === []) {
            return;
        }

        $contribution = Contribution::integration('sanctum', $context->actionSource());
        $requirement = [];

        foreach ($modes as $mode) {
            $name = $mode === SanctumDetector::STATEFUL
                ? $context->components->registerSecurityScheme(SanctumScheme::STATEFUL, SanctumScheme::stateful($this->sessionCookie($context)))
                : $context->components->registerSecurityScheme(SanctumScheme::TOKEN, SanctumScheme::token());

            $requirement[] = [$name => []];
        }

        $operation->setSecurity($requirement, $contribution);
    }

    /**
     * The route's supported modes ∩ the document's allowed set, keeping token-before-stateful order.
     *
     * @return list<string>
     */
    private function effectiveModes(RouteContext $context): array
    {
        $allowed = $this->allowedModes($context);
        $supported = $this->detector->supportedModes(
            $context->route->middleware,
            AuthGuardDrivers::map($this->config?->get('auth.guards')),
            $this->defaultGuard(),
        );

        return array_values(array_filter($supported, static fn (string $mode): bool => in_array($mode, $allowed, true)));
    }

    /**
     * @return list<string>
     */
    private function allowedModes(RouteContext $context): array
    {
        $modes = $context->document->integration('sanctum')['modes'] ?? null;
        if (! is_array($modes)) {
            return self::DEFAULT_MODES;
        }

        $filtered = array_values(array_filter(
            array_map(static fn (mixed $m): string => is_string($m) ? $m : '', $modes),
            static fn (string $m): bool => in_array($m, self::DEFAULT_MODES, true),
        ));

        return $filtered === [] ? self::DEFAULT_MODES : $filtered;
    }

    /**
     * Sanctum's stateful cookie *is* the Laravel session cookie, so: per-document override, else the app's
     * real `session.cookie`, else Laravel's default name.
     *
     * Everything below the override is reported. A cookie name carries no signal a URL's host does, and
     * Laravel's shipped `config/session.php` reads `env('SESSION_COOKIE', Str::slug(env('APP_NAME'), '_')
     * .'_session')` — so the published name is whatever the build machine's environment happened to say,
     * and renaming the app changes the document. It is emitted anyway because it is contract-bearing:
     * an `apiKey`-in-cookie scheme naming the wrong cookie makes every client send the wrong one.
     */
    private function sessionCookie(RouteContext $context): string
    {
        $cookie = $context->document->integration('sanctum')['cookie'] ?? null;
        if (is_string($cookie) && $cookie !== '') {
            return $cookie;
        }

        $signature = $context->route->signature($context->httpMethod());
        $sessionCookie = $this->config?->get('session.cookie');

        if (! is_string($sessionCookie) || $sessionCookie === '') {
            $context->components->addDiagnostic(MachineDependentValue::forDefault(
                self::PUBLISHED, 'laravel_session', 'session.cookie', 'integrations.sanctum.cookie', $signature,
            ));

            return 'laravel_session';
        }

        $context->components->addDiagnostic(MachineDependentValue::forValue(
            self::PUBLISHED, $sessionCookie, 'session.cookie', 'integrations.sanctum.cookie', $signature,
        ));

        return $sessionCookie;
    }

    /** The app's default auth guard, for resolving bare `auth`. */
    private function defaultGuard(): string
    {
        return AuthGuardDrivers::defaultGuard($this->config?->get('auth.defaults.guard'));
    }
}
