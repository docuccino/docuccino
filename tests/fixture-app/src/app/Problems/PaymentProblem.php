<?php

declare(strict_types=1);

namespace App\Problems;

/** A problem that hands its members to the parent's constructor to assign. */
final class PaymentProblem extends DataObject
{
    public string $type;

    public string $title;

    public int $balance;

    public function __construct(string $title, int $balance)
    {
        parent::__construct(['title' => $title, 'balance' => $balance]);
        $this->type = 'https://example.com/problems/payment';
    }
}
