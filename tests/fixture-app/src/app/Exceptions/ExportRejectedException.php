<?php

declare(strict_types=1);

namespace App\Exceptions;

use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * The static-factory idiom: one private constructor with the status defaulted, and named factories for the
 * cases the API tells apart. Nothing outside the class can build it, and no factory writes the status slot,
 * so 422 is what every instance carries rather than what most of them happen to.
 */
final class ExportRejectedException extends HttpException
{
    /**
     * @param  list<string>  $columns
     */
    private function __construct(private readonly array $columns, int $statusCode = 422)
    {
        parent::__construct($statusCode, 'The export was rejected.');
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
