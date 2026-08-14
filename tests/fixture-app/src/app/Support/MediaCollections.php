<?php

declare(strict_types=1);

namespace App\Support;

/**
 * The tenant's configured media collections. The valid names come out of config at runtime, so nothing
 * about them is written at the `Rule::in(...)` call site that allow-lists them.
 */
final class MediaCollections
{
    /**
     * @return list<string>
     */
    public static function validNames(): array
    {
        /** @var list<string> $names */
        $names = config('media.collections', ['default', 'avatars', 'documents']);

        return $names;
    }
}
