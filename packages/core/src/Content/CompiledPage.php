<?php

declare(strict_types=1);

namespace Docuccino\Core\Content;

/**
 * The file-derived facts for one narrative page, produced by the adapter's markdown compiler and
 * handed to the core {@see ContentResolver}. This is the core/adapter contract: reading the
 * filesystem + frontmatter is the adapter's job (framework/IO input); assigning page ids, resolving
 * directives against the assembled document and building the nav tree is core's (document input).
 *
 * `navType` selects how the page appears in the nav tree: `page` (a link to itself), or `operation`
 * / `tag` (a reference node whose `navRef` resolves against the assembled document). `hidden` keeps
 * the page in the registry but drops it from the nav.
 *
 * @internal
 */
final readonly class CompiledPage
{
    /**
     * @param  list<string>  $tags
     */
    public function __construct(
        public string $slug,
        public string $body,
        public string $sourceFile,
        public string $sourceHash,
        public ?string $title = null,
        public ?string $summary = null,
        public ?int $order = null,
        public array $tags = [],
        public ?string $group = null,
        public bool $hidden = false,
        public string $navType = 'page',
        public ?string $navRef = null,
    ) {}
}
