<?php

declare(strict_types=1);

namespace Docuccino\Inference\PhpStan\Tests\Support\Fixtures\Echoes;

use Symfony\Component\HttpFoundation\Response;

/**
 * Every way a written echo is NOT the value sent: a property anyone may write again, one the constructor may
 * return before writing, a promoted one the argument initialised, and a table read keyed by a property that
 * is not readonly.
 */
final class UnfixedEchoes
{
    public string $title;

    public int $code;

    public readonly string $reason;

    public readonly string $late;

    public function __construct(Response $rendered, public readonly int $status = 0)
    {
        $this->title = Response::$statusTexts[$rendered->getStatusCode()] ?? 'Error';
        $this->code = $rendered->getStatusCode();
        $this->reason = Response::$statusTexts[$this->code] ?? 'Error';
        if ($status === 0) {
            return;
        }
        $this->late = Response::$statusTexts[$rendered->getStatusCode()] ?? 'Error';
    }
}
