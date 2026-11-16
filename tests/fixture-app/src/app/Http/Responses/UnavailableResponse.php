<?php

declare(strict_types=1);

namespace App\Http\Responses;

/** A retry-later response that leaves its parent's status empty, and so is sent with the parent's 503. */
class UnavailableResponse extends RetryLaterResponse
{
    public function __construct()
    {
        parent::__construct(['retry' => false]);
    }
}
