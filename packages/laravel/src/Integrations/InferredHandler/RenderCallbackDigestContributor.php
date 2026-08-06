<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Integrations\InferredHandler;

use Docuccino\Core\Extensions\Contracts\EnvironmentDigestContributor;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Throwable;

/**
 * Contributes the booted app's registered render-callback set (exception FQCN + source location, in
 * registration order) to the environment digest (design §10, A4). Adding/removing/replacing a
 * `$exceptions->render(…)` handler must re-document the inferred-handler error tier — the add-a-handler
 * asymmetry the per-file dependency hashes alone miss. Always-on (the inferred-handler tier ships as a
 * built-in), so this segment is present for every build. Defensive: an unresolvable handler contributes
 * the empty string.
 */
final class RenderCallbackDigestContributor implements EnvironmentDigestContributor
{
    public function __construct(private readonly ExceptionHandler $handler) {}

    public function digest(): string
    {
        try {
            $records = [];
            foreach ((new HandlerReflector($this->handler))->renderCallbacks() as $callback) {
                $records[] = $callback->exceptionType.'@'.$callback->file.':'.$callback->line;
            }

            return 'render:'.implode(',', $records);
        } catch (Throwable) {
            return '';
        }
    }
}
