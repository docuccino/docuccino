<?php

declare(strict_types=1);

namespace App\Exceptions;

final class PortalUnavailableException extends PortalException
{
    public function __construct()
    {
        parent::__construct(503, 'The portal is offline.');
    }
}
