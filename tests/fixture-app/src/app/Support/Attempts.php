<?php

declare(strict_types=1);

namespace App\Support;

use App\Exceptions\OutOfStockException;

/**
 * Helpers an application writes to run work it is handed under a catch of its own: one that swallows what
 * the work throws, one that reports and rethrows it, one that takes a single class, and one that retries
 * the work from inside its catch.
 */
final class Attempts
{
    public static function quietly(callable $work): void
    {
        try {
            $work();
        } catch (\Exception $e) {
            report($e);
        }
    }

    public function quietlyOn(callable $work): void
    {
        try {
            $work();
        } catch (\Exception $e) {
            report($e);
        }
    }

    public static function reporting(callable $work): void
    {
        try {
            $work();
        } catch (\Exception $e) {
            report($e);

            throw $e;
        }
    }

    public static function unlessOutOfStock(callable $work): void
    {
        try {
            $work();
        } catch (OutOfStockException $e) {
            report($e);
        }
    }

    public static function retryingOnce(callable $work): void
    {
        try {
            $work();
        } catch (\Exception) {
            $work();
        }
    }
}
