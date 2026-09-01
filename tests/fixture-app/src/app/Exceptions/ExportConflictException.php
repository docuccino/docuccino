<?php

declare(strict_types=1);

namespace App\Exceptions;

use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * One class with a status per factory: a duplicate is a 409 and an export nobody may start is a 403. The
 * class states neither — only the factory each `throw` names does, and one of them chooses by argument, so
 * it names no single status either.
 */
final class ExportConflictException extends HttpException
{
    /**
     * @param  list<string>  $names
     */
    private function __construct(private readonly array $names, int $statusCode = 409)
    {
        parent::__construct($statusCode, 'The export could not be started.');
    }

    public static function duplicateName(string $name): self
    {
        return new self([$name]);
    }

    public static function notPermitted(): self
    {
        return new self([], 403);
    }

    public static function whenRetryable(bool $retryable): self
    {
        return $retryable ? new self([], 423) : new self([]);
    }

    /**
     * @return list<string>
     */
    public function names(): array
    {
        return $this->names;
    }
}
