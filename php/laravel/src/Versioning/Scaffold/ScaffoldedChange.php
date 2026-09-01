<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Versioning\Scaffold;

use Docuccino\Attributes\Versioning\ApiVersionChange;

/**
 * One change class the scaffolder would write: its name, the version it shipped in, the sentence a
 * consumer reads, and the one verb it declares.
 *
 * One verb per class on purpose. A diff knows which fields moved and nothing about which of them are
 * one story, so a class per difference gives each its own `description` — and the description is the
 * whole reason to scaffold at all. Merging two of them afterwards is a paragraph of editing; splitting
 * one sentence back into two is a rewrite.
 *
 * @internal
 */
final readonly class ScaffoldedChange
{
    /**
     * @param  list<string>  $imports  FQCNs the file imports, {@see ApiVersionChange} excluded — the stub
     *                                 imports that one itself
     * @param  ?string  $note  what the author still has to supply, for the command to report; null when
     *                         the scaffold said everything it could
     */
    public function __construct(
        public string $class,
        public string $since,
        public string $description,
        public string $verb,
        public array $imports,
        public ?string $note = null,
    ) {}

    /** The file this change is written to, under `$directory`. */
    public function file(string $directory): string
    {
        return rtrim($directory, '/').'/'.$this->class.'.php';
    }
}
