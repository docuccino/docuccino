<?php

declare(strict_types=1);

use Docuccino\Core\Extensions\Context\AttributeSet;
use Docuccino\Core\Extensions\Context\DocumentConfig;
use Docuccino\Core\Extensions\Context\RouteContext;
use Docuccino\Core\Extensions\Context\RouteDescriptor;
use Docuccino\Core\Inference\ActionRef;
use Docuccino\Core\Inference\NullTypeEngine;
use Docuccino\Core\Inference\TypeEngine;
use Docuccino\Laravel\Config\DocumentConfigFactory;
use Docuccino\Laravel\Registry\DefaultExtensions;
use Docuccino\Laravel\Support\CanGate;
use Docuccino\Laravel\Support\GateDenial;
use Docuccino\Laravel\Support\GatePoliciesDigestContributor;
use Docuccino\Laravel\Tests\Fixtures\Authorization\Kiosk;
use Docuccino\Laravel\Tests\Fixtures\Authorization\KioskController;
use Docuccino\Laravel\Tests\Fixtures\Authorization\Marquee;
use Docuccino\Laravel\Tests\Fixtures\Authorization\MarqueeAccess;
use Docuccino\Laravel\Tests\Fixtures\Authorization\Placard;
use Docuccino\Laravel\Tests\Fixtures\Authorization\Policies\KioskPolicy;
use Docuccino\Laravel\Tests\Fixtures\Authorization\Policies\PlacardPolicy;
use Docuccino\Laravel\Tests\Support\CountingTypeEngine;
use Docuccino\Laravel\Tests\Support\WorkbenchEngine;
use Illuminate\Auth\Access\Response;
use Illuminate\Contracts\Auth\Access\Gate as GateContract;
use Illuminate\Support\Facades\Gate;

/**
 * `authorization.gate-cannot-deny`: the implicit 403 is synthesized from the PRESENCE of a `can:` gate,
 * and a policy method whose whole body is `return true;` makes it an error no request can provoke.
 *
 * Routes here are registered the way an application writes them — `->can(...)` on a real router, real
 * policy classes, resolution left to Laravel's own convention except where a row is about explicit
 * registration — and every silence row states the shape it is silent ON. The response itself always
 * stays published: a diagnostic cannot drop a real error, and nothing here is certain enough to.
 */
function gateRoutes(): void
{
    $router = app('router');

    // Behind auth, conventional policy, `viewAny` is a literal `return true;` → the 403 is dead.
    $router->get('api/kiosks', [KioskController::class, 'index'])
        ->middleware('auth:web')
        ->can('viewAny', Kiosk::class);

    // Same, resolved through the ROUTE PARAMETER form of the gate rather than a class name.
    $router->get('api/kiosks/{kiosk}', [KioskController::class, 'show'])
        ->middleware('auth:web')
        ->can('view', 'kiosk');

    // An explicitly registered policy no naming convention would find.
    $router->get('api/marquees', [KioskController::class, 'index'])
        ->middleware('auth:web')
        ->can('view', Marquee::class);

    // A policy method that plainly denies.
    $router->patch('api/kiosks-updated', [KioskController::class, 'index'])
        ->middleware('auth:web')
        ->can('update', Kiosk::class);

    // The counter-example: one expression, from a call, naming neither the user nor a permission — and
    // it denies. Anything looser than a literal `return true;` would have hidden a reachable 403 here.
    $router->get('api/kiosks-inspected', [KioskController::class, 'index'])
        ->middleware('auth:web')
        ->can('inspect', Kiosk::class);

    // `true` is in the body and it is reached conditionally.
    $router->get('api/kiosks-audited', [KioskController::class, 'index'])
        ->middleware('auth:web')
        ->can('audit', Kiosk::class);

    // A policy carrying a before() method, which answers for every ability it covers.
    $router->get('api/placards', [KioskController::class, 'index'])
        ->middleware('auth:web')
        ->can('view', Placard::class);

    // An ability-only gate: no model argument, so it resolves to a Gate::define'd closure and no policy
    // method exists to read.
    $router->get('api/kiosks-abilities', [KioskController::class, 'index'])
        ->middleware('auth:web')
        ->can('viewAny');

    // A `signed` middleware alongside an undeniable gate — the 403 is reachable through the signature.
    $router->get('api/kiosks-signed', [KioskController::class, 'index'])
        ->middleware(['auth:web', 'signed'])
        ->can('viewAny', Kiosk::class);

    // An undeniable gate on a route a GUEST can reach, whose policy method refuses guests: Laravel
    // denies that without ever calling the method.
    $router->get('api/kiosks-public', [KioskController::class, 'index'])
        ->can('view', Kiosk::class);

    // The same route with a guest-admitting method, which is why the row above is about guests and not
    // about the body.
    $router->get('api/kiosks-public-any', [KioskController::class, 'index'])
        ->can('viewAny', Kiosk::class);

    // The author has already dropped the response, so there is no 403 to report on.
    $router->get('api/kiosks-muted', [KioskController::class, 'muted'])
        ->middleware('auth:web')
        ->can('viewAny', Kiosk::class);

    $router->getRoutes()->refreshNameLookups();
}

beforeEach(function (): void {
    bindStubEngine();
    Gate::policy(Marquee::class, MarqueeAccess::class);
    gateRoutes();
});

/** The gate-reachability diagnostics of one build, keyed by the route signature they name. */
function gateFindings(): array
{
    $found = [];
    foreach (generateDocument()->diagnostics as $diagnostic) {
        if ($diagnostic->code === 'authorization.gate-cannot-deny') {
            $found[(string) $diagnostic->routeSignature] = $diagnostic;
        }
    }

    return $found;
}

it('reports the gate a policy method cannot deny, and keeps publishing the 403', function (): void {
    $findings = gateFindings();
    $document = generateDocument()->document->toArray();

    expect($findings)->toHaveKey('GET /api/kiosks')
        ->and($findings['GET /api/kiosks']->severity->value)->toBe('info')
        ->and($findings['GET /api/kiosks']->message)->toBe(
            "Publishes a 403 from ->can('viewAny', Docuccino\\Laravel\\Tests\\Fixtures\\Authorization\\Kiosk::class), "
            .'but Docuccino\\Laravel\\Tests\\Fixtures\\Authorization\\Policies\\KioskPolicy::viewAny() returns true unconditionally, '
            .'so the gate cannot deny.',
        )
        ->and($findings['GET /api/kiosks']->help)->toContain('#[IgnoreResponse(403)]')
        // The point of the diagnostic is that the response STAYS. Dropping a real error needs a
        // certainty this does not have, so the document is unchanged and the report is the whole fix.
        ->and($document['paths']['/api/kiosks']['get']['responses'])->toHaveKey('403');
});

it('resolves the gate through the route parameter form', function (): void {
    expect(gateFindings())->toHaveKey('GET /api/kiosks/{kiosk}')
        ->and(gateFindings()['GET /api/kiosks/{kiosk}']->message)
        ->toContain("->can('view', 'kiosk')")
        ->toContain('KioskPolicy::view()');
});

it('resolves a policy nothing but an explicit Gate::policy() registration would find', function (): void {
    expect(gateFindings())->toHaveKey('GET /api/marquees')
        ->and(gateFindings()['GET /api/marquees']->message)->toContain('MarqueeAccess::view()');
});

it('stays silent on every gate shape that can deny', function (string $signature): void {
    expect(gateFindings())->not->toHaveKey($signature);
})->with([
    'a policy method that reads state' => ['PATCH /api/kiosks-updated'],
    'one expression from a call, no $user and no permission' => ['GET /api/kiosks-inspected'],
    'true returned conditionally' => ['GET /api/kiosks-audited'],
    'a policy with a before() method' => ['GET /api/placards'],
    'an ability-only gate with no policy behind it' => ['GET /api/kiosks-abilities'],
    'a signed middleware holding the same 403 up' => ['GET /api/kiosks-signed'],
    'a guest-reachable route whose policy method refuses guests' => ['GET /api/kiosks-public'],
    'a 403 the author already dropped' => ['GET /api/kiosks-muted'],
]);

it('still publishes the 403 on every route it stays silent about', function (string $path, string $method): void {
    $document = generateDocument()->document->toArray();

    expect($document['paths'][$path][$method]['responses'] ?? [])->toHaveKey('403');
})->with([
    ['/api/kiosks-updated', 'patch'],
    ['/api/kiosks-inspected', 'get'],
    ['/api/kiosks-audited', 'get'],
    ['/api/placards', 'get'],
    ['/api/kiosks-abilities', 'get'],
    ['/api/kiosks-signed', 'get'],
    ['/api/kiosks-public', 'get'],
]);

it('reports the guest-reachable route whose policy method admits guests', function (): void {
    // The pair to the silence row above: same route shape, same literal `return true;`, and the only
    // difference is a first parameter Laravel will pass null to.
    expect(gateFindings())->toHaveKey('GET /api/kiosks-public-any');
});

it('stays silent on every gate once a Gate::before hook is registered', function (): void {
    // Executed rather than asserted: the hook can answer for any ability, so nothing a policy body says
    // settles the question any more — including the routes that report without it.
    $before = gateFindings();
    expect($before)->toHaveKey('GET /api/kiosks');

    Gate::before(static fn (): ?bool => null);

    expect(gateFindings())->toBe([]);
});

it('stays silent on every gate once a Gate::after hook is registered', function (): void {
    expect(gateFindings())->toHaveKey('GET /api/kiosks');

    Gate::after(static fn (): ?bool => null);

    expect(gateFindings())->toBe([]);
});

/*
 * Cache soundness. The report is a fact one route's build found, so a warm hit has to replay it, and
 * everything the decision READ has to key the fragment — the policy's own file above all, and the gate
 * registrations, which no route file reflects at all.
 */

afterEach(function (): void {
    removeFragmentCacheDirs('fragments');
});

it('reports the same gates on a warm build as on a cold one', function (): void {
    fragmentCacheDir('fragments');

    $cold = diagnosticRecords(generateDocument()->diagnostics);
    $warm = diagnosticRecords(generateDocument()->diagnostics);

    expect($warm)->toBe($cold)
        ->and(json_encode($warm))->toContain('authorization.gate-cannot-deny');
});

/**
 * Build cold, then warm, and hand back the counting engine with a warm build already proven — every
 * invalidation row below is otherwise satisfied by a cache that was never working. ONE engine through-
 * out on purpose: its class is part of the build fingerprint, so swapping it mid-test misses everything
 * for a reason that has nothing to do with what is being asserted.
 */
function gateWarmedEngine(): CountingTypeEngine
{
    fragmentCacheDir('fragments');
    $engine = new CountingTypeEngine(WorkbenchEngine::make());
    app()->instance(TypeEngine::class, $engine);

    generateDocument();
    $engine->analyzeCount = 0;
    generateDocument();

    expect($engine->analyzeCount)->toBe(0);

    return $engine;
}

it('invalidates the fragment when the policy method it read is edited', function (): void {
    // Written to a temp file, because the fact is about the fragment KEY rather than about any
    // particular policy: the file a verdict was read out of has to be one the route depends on.
    $file = sys_get_temp_dir().'/docuccino-gate-policy-'.uniqid('', true).'.php';
    // Namespaced, because the `can:` middleware tells a class argument from a route-parameter name by
    // looking for a namespace separator — a global class would reach the gate as a parameter name.
    $namespace = 'DocuccinoTempGate'.dechex(random_int(0, PHP_INT_MAX));
    $subject = $namespace.'\\Kiosk';
    $class = $namespace.'\\KioskPolicy';
    $body = 'return true;';
    $source = static fn (string $body): string => "<?php\nnamespace $namespace;\nclass Kiosk {}\nclass KioskPolicy { public function viewAny(?object \$user): bool { $body } }\n";
    file_put_contents($file, $source($body));
    require $file;

    app('router')->get('api/kiosks-temp', [KioskController::class, 'index'])
        ->middleware('auth:web')
        ->can('viewAny', $subject);
    app('router')->getRoutes()->refreshNameLookups();
    Gate::policy($subject, $class);

    $engine = gateWarmedEngine();
    expect(gateFindings())->toHaveKey('GET /api/kiosks-temp');

    // Tighten the policy on disk. The class is already loaded, so nothing about this build changes
    // except the file's hash — which is exactly the claim.
    file_put_contents($file, $source('return $user !== null;'));
    clearstatcache();
    touch($file, time() + 5);

    $engine->analyzeCount = 0;
    generateDocument();

    expect($engine->analyzeCount)->toBeGreaterThan(0);

    unlink($file);
});

it('keys the fragment cache on the app\'s gate registrations', function (): void {
    // A `Gate::policy()` call, a policy-name guesser and a `Gate::before` hook all live in a service
    // provider, which no route records. Without this the first build after one was added would serve
    // every warm fragment the verdict the OLD registrations implied.
    $engine = gateWarmedEngine();

    Gate::before(static fn (): ?bool => null);
    generateDocument();

    expect($engine->analyzeCount)->toBeGreaterThan(0);
});

it('contributes the gate registrations whatever a document turns off', function (): void {
    // Gates are the framework's own authorization vocabulary, so the contributor is in the set for a
    // document with every integration disabled — the accident an integration-gated one would be.
    /** @var array<string, mixed> $raw */
    $raw = config('docuccino.documents.default');
    foreach (array_keys((array) ($raw['integrations'] ?? [])) as $integration) {
        $raw['integrations'][$integration]['enabled'] = false;
    }

    $extensions = DefaultExtensions::all(app(DocumentConfigFactory::class)->make('default', $raw, 'skeleton'));

    expect($extensions)->toContain(GatePoliciesDigestContributor::class);
});

it('digests every gate registration that can change a verdict', function (): void {
    $gate = app(GateContract::class);
    $digest = static fn (): string => (new GatePoliciesDigestContributor($gate))->digest();

    $base = $digest();
    expect($base)->toContain('gate-policies:')
        ->and($base)->toContain('before:0')
        ->and($base)->toContain('after:0')
        ->and($base)->toContain('guesser:n');

    // A registration beforeEach has not already made, so the difference is this call's.
    Gate::policy(Placard::class, PlacardPolicy::class);
    $withPolicy = $digest();

    Gate::before(static fn (): ?bool => null);
    $withHook = $digest();

    Gate::guessPolicyNamesUsing(static fn (string $class): string => $class.'Policy');
    $withGuesser = $digest();

    expect($withPolicy)->not->toBe($base)
        ->and($withHook)->not->toBe($withPolicy)
        ->and($withGuesser)->not->toBe($withHook)
        ->and($withGuesser)->toContain('guesser:y');
});

it('digests a policy map by content rather than by the order it was registered', function (): void {
    $one = app(GateContract::class);
    $one->policy(Marquee::class, MarqueeAccess::class);
    $one->policy(Placard::class, PlacardPolicy::class);
    $first = (new GatePoliciesDigestContributor($one))->digest();

    $two = clone app(GateContract::class);
    $two->policy(Placard::class, PlacardPolicy::class);
    $two->policy(Marquee::class, MarqueeAccess::class);

    expect((new GatePoliciesDigestContributor($two))->digest())->toBe($first);
});

it('says nothing it cannot read, rather than guessing', function (): void {
    // A Gate implementation this cannot reflect contributes the empty string and counts as one that HAS
    // hooks, so the check degrades to silence instead of to a confident claim about someone else's Gate.
    $foreign = new class implements GateContract
    {
        public function has($ability): bool
        {
            return false;
        }

        public function define($ability, $callback): self
        {
            return $this;
        }

        public function resource($name, $class, ?array $abilities = null): self
        {
            return $this;
        }

        public function policy($class, $policy): self
        {
            return $this;
        }

        public function before(callable $callback): self
        {
            return $this;
        }

        public function after(callable $callback): self
        {
            return $this;
        }

        public function allows($ability, $arguments = []): bool
        {
            return true;
        }

        public function denies($ability, $arguments = []): bool
        {
            return false;
        }

        public function check($abilities, $arguments = []): bool
        {
            return true;
        }

        public function any($abilities, $arguments = []): bool
        {
            return true;
        }

        public function authorize($ability, $arguments = []): Response
        {
            return Response::allow();
        }

        public function inspect($ability, $arguments = []): Response
        {
            return Response::allow();
        }

        public function raw($ability, $arguments = []): bool
        {
            return true;
        }

        public function getPolicyFor($class): ?object
        {
            return new KioskPolicy;
        }

        public function forUser($user): self
        {
            return $this;
        }

        public function abilities(): array
        {
            return [];
        }
    };

    $context = new RouteContext(
        route: new RouteDescriptor(['GET'], 'api/kiosks', middleware: ['auth:web', 'can:viewAny,'.Kiosk::class]),
        actionRef: new ActionRef('', KioskController::class, 'index'),
        attributes: new AttributeSet([]),
        engine: new NullTypeEngine,
        document: new DocumentConfig('default', [], authMiddleware: 'auth*'),
    );

    $gate = CanGate::parse('can:viewAny,'.Kiosk::class);

    expect($gate)->not->toBeNull()
        ->and((new GateDenial($foreign))->undeniablePolicyMethod($context, $gate))->toBeNull()
        ->and((new GatePoliciesDigestContributor($foreign))->digest())->toBe('')
        // Anti-vacuity: the real Gate answers for the very same context, so the null above is about the
        // Gate this cannot read and not about the fixture.
        ->and((new GateDenial(app(GateContract::class)))->undeniablePolicyMethod($context, $gate))
        ->toBe(KioskPolicy::class.'::viewAny');
});
