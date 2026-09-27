<?php

declare(strict_types=1);

namespace App\Exceptions;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Throwable;

/**
 * Mappers an application hands `$exceptions->map()`: each takes the exception thrown and returns the one the
 * handler goes on to render. Analysed by file+line like a render callback, with the parameter narrowed to
 * the class thrown and every reachable return read as the exception it builds.
 */
class ExceptionMappers
{
    /** The one-line idiom: a domain failure translated to an HTTP status. */
    public function literalStatus(): callable
    {
        return fn (OutOfStockException $e) => new HttpException(409, $e->getMessage(), $e);
    }

    /** The same, as a closure with a local built one statement before the return. */
    public function viaLocal(): callable
    {
        return function (OutOfStockException $e): HttpException {
            $translated = new HttpException(402, 'Payment required.', $e);

            return $translated;
        };
    }

    /** A class that fixes its status itself, built by its static factory. */
    public function viaFactory(): callable
    {
        return fn (OrderConflictException $e) => ExportConflictException::duplicateName($e->getMessage());
    }

    /** A framework exception that carries its status in its class. */
    public function frameworkClass(): callable
    {
        return fn (ModelNotFoundException $e) => new ConflictHttpException('Gone elsewhere.', $e);
    }

    /** A status read at run time. */
    public function dynamicStatus(): callable
    {
        return fn (OutOfStockException $e) => new HttpException($e->getCode() ?: 500, $e->getMessage());
    }

    /** A missing model translated at a status read at run time. */
    public function dynamicMissing(): callable
    {
        return fn (ModelNotFoundException $e) => new HttpException($e->getCode() ?: 404, 'Not here.');
    }

    /** Two translations, chosen at run time. */
    public function twoWays(): callable
    {
        return fn (OutOfStockException $e) => $e->getCode() === 1
            ? new HttpException(402, 'Payment required.')
            : new AuthorizationException('Not yours.');
    }

    /** Translated for one subclass, handed back unchanged for everything else it is keyed on. */
    public function narrowed(): callable
    {
        return function (Throwable $e): Throwable {
            if ($e instanceof OutOfStockException) {
                return new HttpException(410, 'Discontinued.');
            }

            return $e;
        };
    }

    /** A return this build cannot name a class for. */
    public function unnamed(): callable
    {
        return fn (OrderConflictException $e) => $e->getPrevious() ?? new \RuntimeException('unknown');
    }
}
