<?php

declare(strict_types=1);

namespace App\Problems;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** The members and constructor of {@see InstanceProblem}, written out in one class with no parent. */
final class FlatProblem
{
    public readonly string $type;

    public readonly string $title;

    public readonly int $status;

    /** Only sent when the response said more than its status text. */
    public string $detail;

    public readonly string $instance;

    public function __construct(Response $rendered, Request $request)
    {
        $this->type = 'about:blank';
        $this->status = $rendered->getStatusCode();
        $this->title = Response::$statusTexts[$this->status] ?? 'Error';

        $message = $rendered instanceof JsonResponse ? ($rendered->getData(true)['message'] ?? null) : null;
        if (is_string($message) && $message !== '' && $message !== $this->title) {
            $this->detail = $message;
        }

        $this->instance = '/'.$request->path();
    }
}
