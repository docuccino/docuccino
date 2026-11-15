<?php

declare(strict_types=1);

namespace App\Exceptions\Validation;

use Illuminate\Validation\ValidationException;

/** A validation failure of the application's own, answered with the framework's messages. */
final class SubmissionInvalidException extends ValidationException {}
