<?php

declare(strict_types=1);

namespace App\Http\Responses;

use App\Enums\ExportFault;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** A stream sent with the status of the export fault its constructor names. */
class LockedStreamResponse extends StreamedResponse
{
    public function __construct(callable $callback)
    {
        parent::__construct($callback, ExportFault::Locked->value);
    }
}
