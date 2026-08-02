<?php

declare(strict_types=1);

/**
 * The dogfooding rule (design §6, arch-enforced): built-in integrations under
 * `Docuccino\Laravel\Integrations\*` may consume only the public extension surface — the core
 * extension contracts, the type/rule/validation value objects, drafts, inference, diagnostics and
 * provenance — plus the framework (Illuminate) and the shared php-parser boundary library. They must
 * never reach into a core internal (Document, Identity, Emit, Canonical, Overlay, the core Validator)
 * or the adapter's own pipeline/registry/routing wiring. A new integration is thus forced to go
 * through the same public API a third-party package would.
 */
arch('built-in integrations consume only the public extension surface')
    ->expect('Docuccino\Laravel\Integrations')
    ->toOnlyUse([
        'Docuccino\Core\Extensions\Contracts',
        'Docuccino\Core\Extensions\Validation',
        'Docuccino\Core\Extensions\Context',
        'Docuccino\Core\Extensions\Schema',
        'Docuccino\Core\Extensions\Ordering',
        'Docuccino\Core\Draft',
        'Docuccino\Core\Inference',
        'Docuccino\Core\Patch',
        'Docuccino\Core\Diagnostics',
        'Docuccino\Core\Provenance',
        'Docuccino\Attributes',
        'Docuccino\Laravel\Integrations',
        'Illuminate',
        'PhpParser',
    ]);

arch('built-in integrations never reach into core internals or adapter wiring')
    ->expect('Docuccino\Laravel\Integrations')
    ->not->toUse([
        'Docuccino\Core\Document',
        'Docuccino\Core\Identity',
        'Docuccino\Core\Emit',
        'Docuccino\Core\Canonical',
        'Docuccino\Core\Overlay',
        'Docuccino\Core\Validation',
        'Docuccino\Laravel\Pipeline',
        'Docuccino\Laravel\Registry',
        'Docuccino\Laravel\Routing',
    ]);
