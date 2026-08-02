<?php

declare(strict_types=1);

namespace Docuccino\Core\Extensions\Context;

use Docuccino\Core\Extensions\Contracts\RouteResolver;

/**
 * A framework-agnostic description of one discovered route, produced by a
 * {@see RouteResolver}. The action reference is opaque
 * (a `Class@method` string, a `Class` invokable, or the sentinel `Closure` for closure
 * routes) — the adapter's {@see RouteContext} carries the
 * resolved reflection.
 */
final readonly class RouteDescriptor
{
    /**
     * @param  list<string>  $methods  upper-case HTTP methods (a route may answer several)
     * @param  string  $uri  the route template, always leading-slashed (`/api/forms/{form}`)
     * @param  list<string>  $middleware
     */
    public function __construct(
        public array $methods,
        public string $uri,
        public ?string $name = null,
        public ?string $action = null,
        public array $middleware = [],
    ) {}

    /** The primary documentable HTTP method (lower-case) — the first non-HEAD method. */
    public function primaryMethod(): string
    {
        foreach ($this->methods as $method) {
            if (strtoupper($method) !== 'HEAD') {
                return strtolower($method);
            }
        }

        return strtolower($this->methods[0] ?? 'get');
    }

    /** A stable, human-readable signature for diagnostics: `GET /api/forms`. */
    public function signature(): string
    {
        return strtoupper($this->primaryMethod()).' '.$this->uri;
    }
}
