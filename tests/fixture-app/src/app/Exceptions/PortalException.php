<?php

declare(strict_types=1);

namespace App\Exceptions;

use Docuccino\Attributes\ErrorComponent;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * The base every portal error extends, naming the component they would all publish under. One name over
 * three different bodies is exactly what a class anchor can say and no more — which is why the renderer's
 * arms carry names of their own.
 */
#[ErrorComponent('PortalProblem')]
abstract class PortalException extends HttpException {}
