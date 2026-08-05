<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Integrations\RateLimit;

use Docuccino\Core\Diagnostics\Diagnostic;
use Docuccino\Core\Diagnostics\Severity;
use Docuccino\Core\Draft\OperationDraft;
use Docuccino\Core\Extensions\Context\RouteContext;
use Docuccino\Core\Extensions\Contracts\OperationExtension;
use Docuccino\Core\Extensions\Contracts\OperationPhase;
use Docuccino\Core\Patch\Contribution;
use Illuminate\Cache\RateLimiter;

/**
 * Documents a `429 Too Many Requests` response (with `Retry-After` + `X-RateLimit-*` headers) on any
 * operation whose route carries a `throttle` middleware (design §Phase 4 — rate limiting). Numeric
 * throttles (`throttle:60,1`) document the concrete limit; a named limiter (`throttle:api`) is
 * introspected against the booted app's `RateLimiter::for` registrations — the 429 is still
 * documented, but its numbers cannot be recovered without executing the limiter closure, so it is
 * emitted without them plus an info diagnostic. Always-on: `throttle` ships with Laravel.
 */
final class RateLimitResponsesExtension implements OperationExtension
{
    public function __construct(
        private readonly RateLimiter $limiters,
        private readonly ThrottleParser $parser = new ThrottleParser,
        private readonly RateLimitResponse $response = new RateLimitResponse,
    ) {}

    public function phase(): OperationPhase
    {
        return OperationPhase::Responses;
    }

    public function handle(OperationDraft $operation, RouteContext $context): void
    {
        $limits = $this->throttles($context);
        if ($limits === []) {
            return;
        }

        $limit = $limits[0];

        if (count($limits) > 1) {
            $this->reportMultiple($limits, $context);
        }

        if ($limit->isNamed()) {
            $this->reportNamedLimiter($limit, $context);
        }

        $contribution = Contribution::integration('rate-limit', $context->actionSource());
        $response = $operation->response('429');

        $built = $this->response->build($limit);

        $description = $built['description'];
        if (is_string($description)) {
            $response->setDescription($description, $contribution);
        }
        $response->set('headers', $built['headers'], $contribution);

        $content = $built['content'];
        if (is_array($content)) {
            foreach ($content as $mediaType => $media) {
                $schema = is_array($media) && is_array($media['schema'] ?? null) ? $media['schema'] : [];
                foreach ($schema as $keyword => $value) {
                    $response->content((string) $mediaType)->set((string) $keyword, $value, $contribution);
                }
            }
        }
    }

    /**
     * @return list<ThrottleLimit>
     */
    private function throttles(RouteContext $context): array
    {
        $limits = [];
        foreach ($context->route->middleware as $middleware) {
            $limit = $this->parser->parse($middleware);
            if ($limit !== null) {
                $limits[] = $limit;
            }
        }

        return $limits;
    }

    /**
     * @param  list<ThrottleLimit>  $limits
     */
    private function reportMultiple(array $limits, RouteContext $context): void
    {
        $context->components->addDiagnostic(new Diagnostic(
            severity: Severity::Info,
            code: 'rate-limit.multiple-throttles',
            message: sprintf(
                'Route carries %d throttle middleware; a single 429 is documented from the first — the others are enforced independently but not separately represented.',
                count($limits),
            ),
            routeSignature: $context->route->signature(),
        ));
    }

    private function reportNamedLimiter(ThrottleLimit $limit, RouteContext $context): void
    {
        $name = (string) $limit->name;
        $registered = $this->limiters->limiter($name) !== null;

        $context->components->addDiagnostic(new Diagnostic(
            severity: Severity::Info,
            code: 'rate-limit.dynamic-limit',
            message: $registered
                ? sprintf('Named rate limiter "%s" is registered but its limit is defined by a closure; the 429 is documented without numeric values.', $name)
                : sprintf('Rate limiter "%s" has no matching RateLimiter::for registration; the 429 is documented without numeric values.', $name),
            routeSignature: $context->route->signature(),
        ));
    }
}
