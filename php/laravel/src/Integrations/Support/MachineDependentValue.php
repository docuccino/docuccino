<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Integrations\Support;

use Docuccino\Core\Diagnostics\Diagnostic;
use Docuccino\Core\Diagnostics\Severity;

/**
 * One rule for any value a producer publishes that came from the build environment rather than from
 * something the document pins: emit it, and say so. Never refuse and never omit — these values are
 * contract-bearing (OAS requires a `tokenUrl` on every flow object, and an `apiKey`-in-cookie scheme
 * with the wrong `name` sends the client's request without the cookie), and a local preview has to
 * keep working.
 *
 * The signal used is the strongest one available for the value:
 *
 * - a URL has a host, and a loopback or local-development host is positive evidence that no consumer
 *   of the published document can reach it ({@see isLocalUrl()}). A public host is equally positive
 *   evidence the value is fine, so it is not reported;
 * - a value no config key answered for is arbitrary — a hard-coded default stood in;
 * - an opaque value (a cookie name) offers neither signal, so the only thing left to go on is that
 *   nothing pinned it and the framework key it came from is one the environment supplies.
 *
 * Severity is Warning throughout, and deliberately: nothing is unbuildable or malformed, so it isn't
 * an Error, but what got published is arbitrary — chosen by the machine rather than by the
 * application — which is exactly the Warning tier. `--fail-on=warning` then stops such a document
 * becoming a released artifact without breaking anyone's local build.
 *
 * The code shares a family with the adapter's `config.machine-dependent-path`, because both say the
 * same thing about the same kind of defect and both are fixed the same way — pin it. That one stays
 * Info: an absolute path only churns the `configHash`, while these are published for a client to act
 * on.
 */
final class MachineDependentValue
{
    public const CODE = 'config.machine-dependent-value';

    /**
     * Hosts that name the build machine itself. `[::1]` is `::1` in URL bracket syntax, so the
     * brackets come off before the lookup rather than earning an entry of their own.
     *
     * @var list<string>
     */
    private const LOOPBACK_HOSTS = ['localhost', '127.0.0.1', '0.0.0.0', '::1'];

    /**
     * Suffixes reserved for local development and documentation (RFC 6761/2606, plus mDNS `.local`),
     * so a host under one of them resolves on somebody's laptop and nowhere else.
     *
     * @var list<string>
     */
    private const LOCAL_SUFFIXES = ['.localhost', '.test', '.local', '.example'];

    /**
     * Whether `$url`'s host names the build machine or a development-only name. Anything without a
     * host — a bare path, a template, a value that is not a URL at all — is not local: there is no
     * evidence either way, and guessing would report a public API as machine-dependent.
     */
    public static function isLocalUrl(string $url): bool
    {
        $host = parse_url($url, PHP_URL_HOST);
        if (! is_string($host) || $host === '') {
            return false;
        }

        // Trailing dot = the fully-qualified spelling of the same name; brackets = IPv6 URL syntax.
        $host = trim(strtolower($host), '[].');

        if (in_array($host, self::LOOPBACK_HOSTS, true)) {
            return true;
        }

        foreach (self::LOCAL_SUFFIXES as $suffix) {
            if (str_ends_with($host, $suffix)) {
                return true;
            }
        }

        return false;
    }

    /**
     * The report for an unpinned URL read from `$configKey`, or null when the URL is a fine thing to
     * publish. Any producer resolving a URL out of framework config gets the rule by calling this.
     */
    public static function forUrl(string $subject, string $url, string $configKey, string $pin, ?string $routeSignature = null): ?Diagnostic
    {
        if (! self::isLocalUrl($url)) {
            return null;
        }

        return self::report(
            sprintf(
                "%s publishes '%s', read from the application's '%s' — its host names the machine this build ran on, so the document sends every client to a host only that machine can reach, and a build elsewhere emits different bytes.",
                $subject,
                $url,
                $configKey,
            ),
            $pin,
            $routeSignature,
        );
    }

    /**
     * The report for an unpinned opaque value read from `$configKey` — no host to inspect, so the
     * value is taken at face value and what is reported is where it came from.
     */
    public static function forValue(string $subject, string $value, string $configKey, string $pin, ?string $routeSignature = null): Diagnostic
    {
        return self::report(
            sprintf(
                "%s publishes '%s', read from the application's '%s', which the framework derives from the environment — so the output becomes machine-dependent: the same code documented elsewhere publishes a different value, and a client acting on the wrong one is rejected.",
                $subject,
                $value,
                $configKey,
            ),
            $pin,
            $routeSignature,
        );
    }

    /**
     * The report for a value `$configKey` answered nothing for, so a hard-coded default stood in. The
     * emitted value is still true of this build — it is what the application is configured with — but
     * nothing chose it.
     */
    public static function forDefault(string $subject, string $value, string $configKey, string $pin, ?string $routeSignature = null): Diagnostic
    {
        return self::report(
            sprintf(
                "%s publishes '%s', which no '%s' supplied — the value is a fallback default, so it describes no deployment and the output becomes machine-dependent.",
                $subject,
                $value,
                $configKey,
            ),
            $pin,
            $routeSignature,
        );
    }

    private static function report(string $message, string $pin, ?string $routeSignature): Diagnostic
    {
        return new Diagnostic(
            severity: Severity::Warning,
            code: self::CODE,
            message: $message,
            routeSignature: $routeSignature,
            help: sprintf(
                "Set docuccino's '%s' to the value clients should be given, so the document says the same thing wherever it is built.",
                $pin,
            ),
        );
    }
}
