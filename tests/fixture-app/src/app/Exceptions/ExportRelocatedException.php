<?php

declare(strict_types=1);

namespace App\Exceptions;

/**
 * A subclass that adds nothing: the base's factory is the only way it is ever built, so it states that
 * status as surely as a class writing the factory itself would.
 */
final class ExportRelocatedException extends ProbeProblemBase {}
