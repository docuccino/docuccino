<?php

declare(strict_types=1);

namespace App\Exceptions;

use Illuminate\Http\JsonResponse;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * An RFC 9457 envelope built from a response the framework ALREADY rendered, the way an application's
 * `$exceptions->respond()` hook reshapes every error in one place: the status is read back off the
 * incoming response, never restated, and a validation failure adds its `errors`.
 */
final class ProblemEnvelope
{
    public const CONTENT_TYPE = 'application/problem+json';

    public static function from(Response $response, Throwable $e): JsonResponse
    {
        $status = $response->getStatusCode();
        $body = [
            'type' => 'about:blank',
            'title' => Response::$statusTexts[$status] ?? 'Error',
            'status' => $status,
        ];
        if ($e instanceof ValidationException) {
            $body['errors'] = $e->errors();
        }

        return new JsonResponse($body, $status, ['Content-Type' => self::CONTENT_TYPE]);
    }

    /** The same envelope written onto the response it was handed, which is then sent as it stands. */
    public static function decorate(Response $response): void
    {
        $response->headers->set('Content-Type', self::CONTENT_TYPE);
        $response->setContent((string) json_encode(['type' => 'about:blank', 'status' => $response->getStatusCode()]));
    }
}
