<?php

declare(strict_types=1);

namespace App\Problems;

/** A problem built from an array its constructor hands to helpers. */
final class HydratedProblem
{
    use CarriesTrace;

    public string $type;

    public string $title;

    public int $status;

    /** Only sent when there is something to say. */
    public string $detail;

    /** @param  array{title: string, status: int, detail?: string}  $attributes */
    public function __construct(array $attributes, ?string $traceId = null)
    {
        $this->type = 'about:blank';
        $this->hydrate($attributes);
        $this->trace($traceId);
    }

    /** @param  array{title: string, status: int, detail?: string}  $attributes */
    private function hydrate(array $attributes): void
    {
        $this->title = $attributes['title'];
        $this->status = $attributes['status'];

        if (isset($attributes['detail'])) {
            $this->detail = $attributes['detail'];
        }
    }
}
