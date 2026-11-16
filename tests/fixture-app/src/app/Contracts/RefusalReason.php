<?php

declare(strict_types=1);

namespace App\Contracts;

use BackedEnum;

/**
 * Why a request was refused: an enum whose cases each say the status they answer with, so the reason and
 * its status are written in one place.
 */
interface RefusalReason extends BackedEnum
{
    public function status(): int;
}
