<?php

declare(strict_types=1);

namespace Docuccino\Core\Extensions\Context;

use Docuccino\Core\Extensions\Contracts\TagMapper;
use Docuccino\Core\Extensions\Schema\DeclarationFiles;

/**
 * Fragment-cache soundness for a document's {@see TagMapper}: a configured mapper's answer lands inside
 * the operation fragment, and the config bag it was named in holds only a string — so the fragment is
 * keyed on the files that mapper answers FROM, or an edit to it leaves the old tags warm.
 *
 * The resolved instance is what gets reflected, never the configured string: `tags.mapper` may name a
 * container binding rather than a class, and the class it hands back may be anonymous. Its own file is
 * only where the question was asked, so {@see DeclarationFiles} answers the rest.
 *
 * Called from wherever a tag actually went THROUGH the mapper, which is what keeps the key local: a route
 * the mapper never answered for — a closure with no `#[Group]`, any route under the `none` tag strategy —
 * records nothing and stays warm across an edit to it.
 *
 * @internal
 */
final class TagMapperKeying
{
    /** Key `$dependencies` on the mapper that shaped a tag, or refuse the fragment the cache if it cannot be. */
    public static function record(RouteDependencies $dependencies, DocumentConfig $document): void
    {
        $mapper = $document->tagMapper;

        if ($mapper === null) {
            return;
        }

        $files = DeclarationFiles::keyableFor($mapper);

        if ($files === null) {
            $dependencies->refuseCaching();

            return;
        }

        $dependencies->addFiles($files);
    }

    /**
     * The class of this document's tag mapper when nothing about it can be keyed, else null. What the
     * build reports, and the same question {@see record()} refuses on — asked once per document rather
     * than once per route, since one line saying so is the whole of what an author can act on.
     */
    public static function unhashableMapper(DocumentConfig $document): ?string
    {
        $mapper = $document->tagMapper;

        return $mapper !== null && DeclarationFiles::keyableFor($mapper) === null ? $mapper::class : null;
    }
}
