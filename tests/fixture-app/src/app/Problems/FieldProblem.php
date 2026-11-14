<?php

declare(strict_types=1);

namespace App\Problems;

use Illuminate\Http\JsonResponse;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;

/** A rendered problem carrying the fields that failed validation, sent as problem+json. */
final class FieldProblem extends RenderedProblem
{
    /** @var array<string, list<string>> */
    public readonly array $errors;

    public function __construct(Response $rendered, ValidationException $e)
    {
        parent::__construct($rendered);

        /** @var array<string, list<string>> $errors */
        $errors = $e->errors();
        $this->errors = $errors;
    }

    public static function from(Response $rendered, ValidationException $e): JsonResponse
    {
        return (new JsonResponse(new self($rendered, $e), $rendered->getStatusCode(), $rendered->headers->all()))
            ->header('Content-Type', 'application/problem+json');
    }
}
