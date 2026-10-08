<?php

declare(strict_types=1);

namespace App\Http\Responses;

use Illuminate\Http\JsonResponse;

/** The application's own JSON response, which inherits the status-text table rather than declaring one. */
class ApiResponse extends JsonResponse {}
