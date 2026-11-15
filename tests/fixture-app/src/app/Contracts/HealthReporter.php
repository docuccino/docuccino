<?php

declare(strict_types=1);

namespace App\Contracts;

use Illuminate\Http\Response;

/**
 * What the application asks of a health reporter, bound to a concrete one in a service provider. A contract
 * has no body to read, so an action relaying its report hands back a plain response nothing describes.
 */
interface HealthReporter
{
    public function report(): Response;
}
