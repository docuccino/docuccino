<?php

declare(strict_types=1);

namespace App\Exceptions;

use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * The named-factory idiom: a private constructor forwarding a defaulted status slot, a marker interface a
 * renderer dispatches on, and one factory per rejection the API tells apart. Every factory folds to a
 * status, so the class states none but each `throw` that names one does.
 */
final class ManifestRejectedException extends HttpException implements SignalsManifestFault
{
    /**
     * @param  array<string, string>  $faults
     */
    private function __construct(private readonly array $faults, int $statusCode = 422)
    {
        parent::__construct($statusCode, 'The manifest was rejected.');
    }

    public static function notCustom(): self
    {
        return new self(['type' => 'not-custom'], 409);
    }

    public static function notFixed(): self
    {
        return new self(['type' => 'not-fixed']);
    }

    public static function notFound(): self
    {
        return new self(['item' => 'missing'], 404);
    }

    /**
     * @return array<string, string>
     */
    public function faults(): array
    {
        return $this->faults;
    }
}
