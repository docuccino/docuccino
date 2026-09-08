<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Support;

use Docuccino\Core\Extensions\Contracts\EnvironmentDigestContributor;
use Docuccino\Laravel\Integrations\Support\AuthConfigDigestContributor;
use Illuminate\Contracts\Auth\Access\Gate;
use ReflectionObject;
use Throwable;

/**
 * Feeds the booted app's gate registrations — the class → policy map, whether a policy-name guesser is
 * installed, and how many `Gate::before`/`Gate::after` hooks there are — into the environment digest
 * (design §10). {@see GateDenial} reads all three, and none of them is reflected by any file a route
 * records: they are written in a service provider, so registering a policy or adding a `before` hook
 * afterwards would leave every warm fragment saying what the old registrations implied.
 *
 * The map's KEYS and VALUES both count, since either changes which policy a `can:` gate resolves to;
 * the hook counts are enough, because a hook's body is never read — its mere presence is what silences
 * the check. Sorted, and an unreadable Gate contributes the empty string.
 *
 * Registered unconditionally: gates are the framework's own authorization vocabulary and belong to no
 * package, the reason {@see AuthConfigDigestContributor} is too.
 */
final class GatePoliciesDigestContributor implements EnvironmentDigestContributor
{
    public function __construct(private readonly Gate $gate) {}

    /** Every property read below, so a Gate missing any of them is unreadable rather than half-read. */
    private const array PROPERTIES = ['policies', 'beforeCallbacks', 'afterCallbacks', 'guessPolicyNamesUsingCallback'];

    public function digest(): string
    {
        try {
            $reflection = new ReflectionObject($this->gate);

            foreach (self::PROPERTIES as $property) {
                if (! $reflection->hasProperty($property)) {
                    // Someone else's Gate. Contributing a made-up segment would key the cache on a fact
                    // nothing here established, so this says nothing instead.
                    return '';
                }
            }

            $records = [];
            foreach ($this->arrayProperty($reflection, 'policies') as $class => $policy) {
                $records[] = (string) $class.'=>'.(is_string($policy) ? $policy : get_debug_type($policy));
            }
            sort($records);

            return 'gate-policies:'.implode(',', $records)
                .'|guesser:'.($reflection->getProperty('guessPolicyNamesUsingCallback')->getValue($this->gate) !== null ? 'y' : 'n')
                .'|before:'.count($this->arrayProperty($reflection, 'beforeCallbacks'))
                .'|after:'.count($this->arrayProperty($reflection, 'afterCallbacks'));
        } catch (Throwable) {
            return '';
        }
    }

    /** @return array<array-key, mixed> */
    private function arrayProperty(ReflectionObject $reflection, string $name): array
    {
        $value = $reflection->getProperty($name)->getValue($this->gate);

        return is_array($value) ? $value : [];
    }
}
