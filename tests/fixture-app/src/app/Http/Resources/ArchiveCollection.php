<?php

declare(strict_types=1);

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * A shared collection that states which resource it holds, the way an application writes one for its
 * analyser.
 *
 * @template TResource
 *
 * @extends AnonymousResourceCollection<TResource>
 */
class ArchiveCollection extends AnonymousResourceCollection
{
    /**
     * @return array{archived: bool}
     */
    public function with(Request $request): array
    {
        return ['archived' => true];
    }
}
