<?php

declare(strict_types=1);

namespace App\Problems;

use AllowDynamicProperties;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\Response;

/** An RFC 9457 problem that also carries whatever else Laravel rendered, its debug members when app.debug is on. */
#[AllowDynamicProperties]
final class DebugProblem
{
    public readonly string $type;

    public readonly string $title;

    public readonly int $status;

    public function __construct(Response $rendered)
    {
        $this->type = 'about:blank';
        $this->status = $rendered->getStatusCode();
        $this->title = Response::$statusTexts[$this->status] ?? 'Error';
    }

    public static function from(JsonResponse $rendered): JsonResponse
    {
        $problem = new self($rendered);

        foreach (array_diff_key((array) $rendered->getData(true), get_class_vars(self::class), ['message' => true]) as $key => $value) {
            $problem->{$key} = $value;
        }

        return (new JsonResponse($problem, $rendered->getStatusCode(), $rendered->headers->all()))
            ->header('Content-Type', 'application/problem+json');
    }

    public static function reported(JsonResponse $rendered): JsonResponse
    {
        $problem = new self($rendered);

        foreach (self::debugMembers($rendered) as $key => $value) {
            $problem->{$key} = $value;
        }

        return (new JsonResponse($problem, $rendered->getStatusCode(), $rendered->headers->all()))
            ->header('Content-Type', 'application/problem+json');
    }

    /** @return array<array-key, mixed> */
    private static function debugMembers(JsonResponse $rendered): array
    {
        return array_diff_key((array) $rendered->getData(true), get_class_vars(self::class), ['message' => true]);
    }
}
