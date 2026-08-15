<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Extensions;

use Docuccino\Core\Draft\OperationDraft;
use Docuccino\Core\Extensions\Context\RouteContext;
use Docuccino\Core\Extensions\Contracts\OperationExtension;
use Docuccino\Core\Extensions\Contracts\OperationPhase;
use Docuccino\Core\Patch\Contribution;

/**
 * Gives a host-bound route (`Route::domain('admin.example.com')->group(...)`) an operation-level
 * `servers` entry naming the host it answers on. OpenAPI has no per-operation host and `servers` is
 * the only member that carries one, so without this a generated client calls an admin or tenant route
 * on the document's default host.
 *
 * The scheme comes from the document's configured servers, because binding a host swaps only the host
 * out of the app URL — https when nothing is configured says so. A templated host
 * (`{tenant}.example.com`) becomes a server variable defaulting to the placeholder's own name, which
 * is as close to a value as the routes can honestly get.
 */
final class RouteServersExtension implements OperationExtension
{
    public function phase(): OperationPhase
    {
        return OperationPhase::Overrides;
    }

    public function handle(OperationDraft $operation, RouteContext $context): void
    {
        $domain = $context->route->domain;
        if ($domain === null || $domain === '') {
            return;
        }

        // `{tenant?}` is the same host segment as `{tenant}` once it reaches a URL.
        $host = preg_replace('/\{([^}]+)\?}/', '{$1}', $domain) ?? $domain;

        $server = ['url' => $this->scheme($context).'://'.$host];

        $variables = $this->variables($host);
        if ($variables !== []) {
            $server['variables'] = $variables;
        }

        $operation->set('servers', [$server], Contribution::fallback());
    }

    /** The first scheme the document's servers state — a relative or unparseable url has none. */
    private function scheme(RouteContext $context): string
    {
        foreach ($context->document->servers as $server) {
            $url = $server['url'] ?? null;
            $scheme = is_string($url) ? parse_url($url, PHP_URL_SCHEME) : null;

            if (is_string($scheme) && $scheme !== '') {
                return $scheme;
            }
        }

        return 'https';
    }

    /**
     * @return array<string, array<string, string>>
     */
    private function variables(string $host): array
    {
        preg_match_all('/\{([^}]+)}/', $host, $matches);

        $variables = [];
        foreach ($matches[1] as $name) {
            $variables[$name] = [
                'default' => $name,
                'description' => sprintf('The "%s" segment of the host this operation is served from.', $name),
            ];
        }

        return $variables;
    }
}
