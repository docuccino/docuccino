<?php

declare(strict_types=1);

namespace App\Exceptions;

use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * The same private-constructor idiom, with a constructor that NORMALISES the status it was handed: a
 * rejection naming no columns is a 400 rather than the 422 the default carries. So the default is no longer
 * what every instance is built with, and neither is what a caller puts in the slot.
 */
final class ExportPartialException extends HttpException
{
    /**
     * @param  list<string>  $columns
     */
    private function __construct(private readonly array $columns, int $statusCode = 422)
    {
        if ($columns === []) {
            $statusCode = 400;
        }

        parent::__construct($statusCode, 'The export was only partly written.');
    }

    /**
     * @param  list<string>  $columns
     */
    public static function forColumns(array $columns): self
    {
        return new self($columns);
    }

    public static function none(): self
    {
        return new self([]);
    }

    /**
     * @return list<string>
     */
    public function columns(): array
    {
        return $this->columns;
    }
}
