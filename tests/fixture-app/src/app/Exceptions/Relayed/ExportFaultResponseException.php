<?php

declare(strict_types=1);

namespace App\Exceptions\Relayed;

use App\Enums\ExportFault;
use Illuminate\Http\Exceptions\HttpResponseException;

/** Builds the response it carries from the fault it is handed, whose value is the status. */
final class ExportFaultResponseException extends HttpResponseException
{
    public function __construct(ExportFault $fault)
    {
        parent::__construct(response()->json(['error' => $fault->name], $fault->value));
    }
}
