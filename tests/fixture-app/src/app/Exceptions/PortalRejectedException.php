<?php

declare(strict_types=1);

namespace App\Exceptions;

final class PortalRejectedException extends PortalException implements HasProblemFields
{
    /**
     * @param  list<string>  $rejected
     */
    public function __construct(private readonly array $rejected = [])
    {
        parent::__construct(422, 'The submission was rejected.');
    }

    /** @return list<string> */
    public function fields(): array
    {
        return $this->rejected;
    }
}
