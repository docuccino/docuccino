<?php

declare(strict_types=1);

namespace App\Problems;

/** A notice that says when to retry, unless retrying cannot help. */
final class RetryNotice
{
    public string $message;

    public int $retryAfter;

    public function __construct(bool $permanent, int $seconds)
    {
        if ($permanent) {
            $this->message = 'This request will not succeed if repeated.';

            return;
        }

        $this->message = 'Try again later.';
        $this->retryAfter = $seconds;
    }
}
