<?php

declare(strict_types=1);

namespace App\Http\Responses;

use App\Enums\ExportFault;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** A stream sent with the status of whichever export fault its caller hands it. */
class FaultStreamResponse extends StreamedResponse
{
    public function __construct(callable $callback, ExportFault $fault)
    {
        parent::__construct($callback, $fault->statusCode());
    }
}
