<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Support;

use Docuccino\Core\Extensions\Context\RouteContext;
use Docuccino\Laravel\Integrations\Support\ParsedClassFile;
use Illuminate\Contracts\Auth\Access\Gate;
use Illuminate\Support\Str;
use PhpParser\Node\Expr\ConstFetch;
use PhpParser\Node\Stmt\Return_;
use ReflectionClass;
use ReflectionMethod;
use ReflectionNamedType;
use ReflectionObject;
use ReflectionParameter;
use Throwable;

/**
 * Whether a route's `can:` gate is one no request can fail — the question behind the implicit 403.
 * That 403 is synthesized from the PRESENCE of a gate, so a policy method whose whole body is
 * `return true;` leaves the document promising an error the endpoint cannot produce, which reaches a
 * consumer as a dead `catch` branch in a generated client.
 *
 * Deliberately narrow, and every uncertainty answers "it can deny": the false negative is a 403 that
 * stays published, the false positive is an author invited to hide a real error. So only a literal,
 * unconditional `return true;` counts — a helper call, a `$user` check, anything read at all is
 * silence. Three shapes decide the gate somewhere the policy method's body cannot be seen, and each is
 * checked rather than assumed away:
 *
 *  - a `Gate::before`/`Gate::after` registration, or a policy `before()` method, either of which can
 *    answer for every ability the policy covers;
 *  - a route a guest can reach whose policy method refuses guests — Laravel denies that without ever
 *    calling the method ({@see methodAllowsGuests()});
 *  - a gate whose argument is not a class a build can name, which resolves to a `Gate::define`d
 *    closure instead of to any policy.
 *
 * The policy is resolved by ASKING the booted app's {@see Gate} rather than by re-deriving Laravel's
 * convention: `getPolicyFor()` is the one answer that already honours `Gate::policy()`, a provider's
 * `$policies`, a model's `#[UsePolicy]` and a custom `guessPolicyNamesUsing()` callback. What no route
 * file reflects keys the fragment cache instead ({@see GatePoliciesDigestContributor}).
 */
final class GateDenial
{
    public function __construct(private readonly Gate $gate) {}

    /**
     * The policy method this gate provably cannot be denied by — `App\Policies\WidgetPolicy::view` — or
     * null whenever the gate can deny OR nothing here could settle it. The two are one answer on
     * purpose: both mean the 403 keeps its place and nothing is reported.
     */
    public function undeniablePolicyMethod(RouteContext $context, CanGate $gate): ?string
    {
        if ($this->hooksRegistered()) {
            return null;
        }

        $model = $this->model($context, $gate);
        if ($model === null) {
            return null;
        }

        // The model's own file decides part of the resolution — a `#[UsePolicy]` attribute lives there.
        $this->recordClassFile($context, $model);

        $policy = $this->policyFor($model);
        if ($policy === null) {
            return null;
        }

        // Both files, because they can differ and either can change the answer: `before()` would be added
        // to the policy class, while an inherited or trait-provided ability method is written elsewhere.
        $this->recordClassFile($context, $policy::class);

        $name = str_contains($gate->ability, '-') ? Str::camel($gate->ability) : $gate->ability;
        if (! method_exists($policy, $name)) {
            // Laravel's own ability→method rule, and a method the policy does not declare falls through
            // to a `Gate::define`d closure — not a body this reads.
            return null;
        }

        $method = new ReflectionMethod($policy, $name);
        $file = $method->getFileName();
        if ($file === false) {
            return null;
        }
        $context->recordDependencyFiles([$file]);

        if (method_exists($policy, 'before')) {
            return null;
        }

        // A guest never reaches a gate behind auth middleware. Where one can, Laravel skips a policy
        // method that refuses guests and denies, so `return true;` is not the whole story.
        if (! AuthMiddlewareDetector::matches($context) && ! $this->methodAllowsGuests($method)) {
            return null;
        }

        return $this->returnsTrueUnconditionally($method, $file) ? $policy::class.'::'.$name : null;
    }

    /**
     * Whether anything is registered that can answer an ability over a policy's head. Read by
     * reflection because the {@see Gate} contract publishes no accessor for either callback list, and a
     * Gate this cannot read counts as one that HAS hooks — the conservative answer, not the convenient
     * one.
     */
    private function hooksRegistered(): bool
    {
        try {
            $reflection = new ReflectionObject($this->gate);

            foreach (['beforeCallbacks', 'afterCallbacks'] as $property) {
                if (! $reflection->hasProperty($property)) {
                    return true;
                }

                $callbacks = $reflection->getProperty($property)->getValue($this->gate);
                if (! is_array($callbacks) || $callbacks !== []) {
                    return true;
                }
            }
        } catch (Throwable) {
            return true;
        }

        return false;
    }

    /** The policy the gate authorizes with, built exactly as an authorize call would build it. */
    private function policyFor(string $model): ?object
    {
        try {
            $policy = $this->gate->getPolicyFor($model);
        } catch (Throwable) {
            // Resolution runs the policy through the container. One that cannot be built is one this
            // cannot read.
            return null;
        }

        return is_object($policy) ? $policy : null;
    }

    /**
     * The class the gate authorizes against, or null where the argument is not one a build can name: no
     * argument at all (an ability-only gate), a quoted literal, or a route parameter nothing binds a
     * model to.
     */
    private function model(RouteContext $context, CanGate $gate): ?string
    {
        $argument = $gate->arguments[0] ?? null;
        if ($argument === null) {
            return null;
        }

        if (CanGate::isClassName($argument)) {
            return ltrim($argument, '\\');
        }

        return $context->routeBindings[$argument] ?? null;
    }

    private function recordClassFile(RouteContext $context, string $class): void
    {
        if (! class_exists($class)) {
            return;
        }

        $file = (new ReflectionClass($class))->getFileName();
        if ($file !== false) {
            $context->recordDependencyFiles([$file]);
        }
    }

    /**
     * Laravel's `methodAllowsGuests`: the first parameter must exist and admit null. A policy method
     * with NO parameters refuses guests, which is why "the method ignores $user" is never the test.
     */
    private function methodAllowsGuests(ReflectionMethod $method): bool
    {
        $parameters = $method->getParameters();

        return isset($parameters[0]) && $this->parameterAllowsGuests($parameters[0]);
    }

    private function parameterAllowsGuests(ReflectionParameter $parameter): bool
    {
        $type = $parameter->getType();
        if ($type instanceof ReflectionNamedType && $type->allowsNull()) {
            return true;
        }

        try {
            return $parameter->isDefaultValueAvailable() && $parameter->getDefaultValue() === null;
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * Whether the method's whole body is a literal `return true;`. Read off the source rather than
     * inferred: the answer has to be certain, and `true` arriving from anything — a call, a variable, a
     * conditional — is a body that can deny.
     */
    private function returnsTrueUnconditionally(ReflectionMethod $method, string $file): bool
    {
        $node = ParsedClassFile::methods($file)[$method->getName()] ?? null;
        // One file can hold several classes and traits declaring one method name, and the parse keys by
        // name alone. The line is what ties a node to the method reflection found.
        if ($node === null || $node->getStartLine() !== $method->getStartLine()) {
            return false;
        }

        $statements = $node->stmts;
        if ($statements === null || count($statements) !== 1) {
            return false;
        }

        $statement = $statements[0];
        if (! $statement instanceof Return_ || ! $statement->expr instanceof ConstFetch) {
            return false;
        }

        return strtolower($statement->expr->name->toString()) === 'true';
    }
}
