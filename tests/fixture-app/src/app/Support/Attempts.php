<?php

declare(strict_types=1);

namespace App\Support;

use App\Exceptions\OutOfStockException;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Helpers an application writes to run work it is handed under a catch of its own: one that swallows what
 * the work throws, logging its message, one that reports and rethrows it, one that hands it to a method of
 * its own that rethrows, one that takes a single class, and one that retries the work from inside its catch.
 */
final class Attempts
{
    public static function quietly(callable $work): void
    {
        try {
            $work();
        } catch (\Exception $e) {
            Log::warning($e->getMessage());
        }
    }

    public function quietlyOn(callable $work): void
    {
        try {
            $work();
        } catch (\Exception $e) {
            Log::warning($e->getMessage());
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

    public function guarded(callable $work): void
    {
        try {
            $work();
        } catch (\Exception $e) {
            $this->fail($e);
        }
    }

    private function fail(Throwable $e): void
    {
        report($e);

        throw $e;
    }

    public static function unlessOutOfStock(callable $work): void
    {
        try {
            $work();
        } catch (OutOfStockException $e) {
            Log::warning($e->getMessage());
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
