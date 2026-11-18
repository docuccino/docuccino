<?php

declare(strict_types=1);

namespace App\Exceptions;

use LogicException;

/** A list builder the application wired wrongly: a defect in the program, never a request's to cause. */
class ListingMisconfiguredException extends LogicException {}
