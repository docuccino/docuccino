<?php

declare(strict_types=1);

namespace App\Exceptions;

/**
 * The refusal to throw: `throw new Refused(TokenRefusal::Expired, '…')`.
 */
final class Refused extends Refusal {}
