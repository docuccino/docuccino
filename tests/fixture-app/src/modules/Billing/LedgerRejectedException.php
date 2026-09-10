<?php

declare(strict_types=1);

namespace Modules\Billing;

use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * The named-factory idiom again, declared in a PSR-4 root OUTSIDE the descend scope — the modular layout
 * an application reaches for once `app/` stops being the only place it writes code.
 */
final class LedgerRejectedException extends HttpException
{
    /**
     * @param  array<string, string>  $faults
     */
    private function __construct(private readonly array $faults, int $statusCode = 422)
    {
        parent::__construct($statusCode, 'The ledger entry was rejected.');
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
