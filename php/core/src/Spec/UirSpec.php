<?php

declare(strict_types=1);

namespace Docuccino\Core\Spec;

/**
 * Which UIR version this build writes, stated once.
 *
 * It was six sites before, and three of them had already drifted — including the adapter's
 * fragment-cache `specVersion`, which is what makes a spec change retire the fragments built under the
 * old one. A stale copy there is under-keying, which is a correctness bug rather than a cost.
 *
 * The `$id` carries major.minor and `x-docuccino.generator.specVersion` carries the patch too, so both
 * URLs are derived from the version rather than written beside it.
 */
final class UirSpec
{
    /** The precise format version an emitted document declares. */
    public const string VERSION = '2.0.0';

    /** The major.minor the schema is published and bundled under. */
    public static function minor(): string
    {
        [$major, $minor] = explode('.', self::VERSION);

        return $major.'.'.$minor;
    }

    /** The document schema an emitted document names, which is also where the schema is served. */
    public static function schemaUrl(): string
    {
        return 'https://spec.docuccino.app/uir/'.self::minor().'/schema.json';
    }

    /**
     * The extension schema: `x-docuccino` alone, applicable on top of any OpenAPI document. The
     * document schema references it, so a validator resolving one needs the other.
     */
    public static function extensionSchemaUrl(): string
    {
        return 'https://spec.docuccino.app/uir/'.self::minor().'/extension.schema.json';
    }
}
