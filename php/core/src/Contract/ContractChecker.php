<?php

declare(strict_types=1);

namespace Docuccino\Core\Contract;

use JsonException;

/**
 * The OAS half of contract checking: match an {@see Exchange} to its operation, pick the documented
 * response for the status, the `content` entry and the `headers` map for the response and the
 * parameters and body for the request, then hand each payload to {@see SchemaCheck}.
 *
 * Where the contract cannot be checked rather than being wrong — a `text/csv` body, a media type or a
 * header with no schema — the outcome passes with a NOTE rather than passing silently. A pass that
 * proved nothing and says nothing is how a suite ends up believing it has contract coverage it does not
 * have.
 */
final class ContractChecker
{
    private readonly SchemaCheck $schema;

    public function __construct(private readonly ContractIndex $index)
    {
        $this->schema = new SchemaCheck($index);
    }

    public function check(Exchange $exchange, bool $checkRequest = true, bool $checkResponse = true): CheckResult
    {
        $operation = $this->index->match($exchange->method, $exchange->path);

        if ($operation === null) {
            return new CheckResult(null);
        }

        return new CheckResult(
            operation: $operation,
            request: $checkRequest ? $this->request($operation, $exchange) : null,
            response: $checkResponse ? $this->response($operation, $exchange) : null,
        );
    }

    /**
     * One half of {@see check()}, which is the entry point — these two are separate only so a test can
     * reach a pairing check() cannot produce.
     *
     * @internal
     */
    public function response(ContractOperation $operation, Exchange $exchange): Outcome
    {
        $document = $this->index->document();
        $documented = $operation->responseFor($document, $exchange->status);

        if ($documented === null) {
            return Outcome::failed([Violation::ofExchange(sprintf(
                'responded %d, which the contract does not document (it documents %s)',
                $exchange->status,
                $operation->documentedStatuses(),
            ))]);
        }

        [$response, $segments] = $documented;

        $headers = $this->responseHeaders($response, $segments, $exchange);
        $body = $this->responseBody($response, $segments, $exchange);

        $violations = [...$headers->violations, ...$body->violations];

        return $violations === []
            ? Outcome::passed(self::note($headers->note, $body->note))
            : Outcome::failed($violations);
    }

    /**
     * The documented `headers` map against what came back.
     *
     * A header the response sent MORE THAN ONCE is checked once per value: the contract says what the
     * header looks like, and a response that sent `Set-Cookie` three times made that claim three times.
     * Joining them into one comma list would hand the schema a value nothing sent, and RFC 9110 forbids
     * joining `Set-Cookie` at all.
     *
     * @param  array<string, mixed>  $response
     * @param  list<string>  $segments
     */
    private function responseHeaders(array $response, array $segments, Exchange $exchange): Outcome
    {
        $document = $this->index->document();

        $violations = [];
        $notes = [];

        foreach (ResponseHeaders::of($document, $response, $segments) as $header) {
            $values = $exchange->responseHeader($header->name);

            if ($values === []) {
                // An absent OPTIONAL header is not a violation — the contract said it might be there.
                if ($header->required) {
                    // The pointer names the DECLARATION, which is the node that says `required`; the
                    // trail is read from its schema, which is the node a producer signs.
                    $violations[] = new Violation(
                        location: $header->label(),
                        pointer: '',
                        message: 'is documented as required, but the response did not send it',
                        schemaPointer: Pointer::of($header->segments),
                        provenance: ProvenanceTrail::at($document, $header->schemaSegments()),
                    );
                }

                continue;
            }

            if (! $header->hasSchema()) {
                $notes[] = isset($header->definition['content'])
                    ? sprintf('%s is documented as a content object, which the check does not decode', $header->label())
                    : sprintf('the contract documents no schema for %s', $header->label());

                continue;
            }

            foreach ($values as $index => $value) {
                $label = count($values) === 1 ? $header->label() : sprintf('%s (value %d)', $header->label(), $index + 1);

                foreach ($this->schema->check(
                    ParameterValue::coerce($value, $header->schema()),
                    $header->schemaSegments(),
                    $label,
                ) as $violation) {
                    $violations[] = $violation;
                }
            }
        }

        return $violations === [] ? Outcome::passed(self::note(...$notes)) : Outcome::failed($violations);
    }

    /**
     * @param  array<string, mixed>  $response
     * @param  list<string>  $segments
     */
    private function responseBody(array $response, array $segments, Exchange $exchange): Outcome
    {
        $content = $response['content'] ?? null;

        if (! is_array($content) || $content === []) {
            return trim($exchange->responseBody) === ''
                ? Outcome::passed()
                : Outcome::failed([Violation::ofExchange(sprintf(
                    'documents no body for %d, but the response returned %d bytes',
                    $exchange->status,
                    strlen($exchange->responseBody),
                ))]);
        }

        /** @var array<string, mixed> $content */
        $requested = MediaType::base($exchange->responseContentType);
        $key = MediaType::select($content, $requested);

        if ($key === null) {
            return Outcome::failed([Violation::ofExchange(sprintf(
                'returned %s, which the contract does not document for %d (it documents %s)',
                $requested ?? 'no content type',
                $exchange->status,
                implode(', ', array_map(strval(...), array_keys($content))),
            ))]);
        }

        return $this->body(
            $exchange->responseBody,
            $content[$key],
            [...$segments, 'content', $key, 'schema'],
            $key,
            'the response body',
            'the response',
        );
    }

    /**
     * The other half. {@see response()}.
     *
     * @internal
     */
    public function request(ContractOperation $operation, Exchange $exchange): Outcome
    {
        $parameters = $this->parameters($operation, $exchange);
        $body = $this->requestBody($operation, $exchange);

        $violations = [...$parameters->violations, ...$body->violations];

        return $violations === []
            ? Outcome::passed(self::note($parameters->note, $body->note))
            : Outcome::failed($violations);
    }

    private function parameters(ContractOperation $operation, Exchange $exchange): Outcome
    {
        $bound = $operation->bind($exchange->path) ?? [];

        $violations = [];
        $notes = [];

        foreach ($operation->parameters as $parameter) {
            $value = match ($parameter->in) {
                'path' => $bound[$parameter->name] ?? null,
                'query' => $exchange->query[$parameter->name] ?? null,
                'header' => $exchange->header($parameter->name),
                'cookie' => $exchange->cookies[$parameter->name] ?? null,
                default => null,
            };

            if ($value === null) {
                // A missing PATH parameter is not a contract violation: the request could not have
                // matched this template without one, so its absence means the template did not bind.
                if ($parameter->required && $parameter->in !== 'path') {
                    $violations[] = new Violation(
                        location: $parameter->label(),
                        pointer: '',
                        message: 'is documented as required, but the request did not send it',
                        schemaPointer: Pointer::of($parameter->segments),
                        provenance: ProvenanceTrail::at($this->index->document(), $parameter->schemaSegments()),
                    );
                }

                continue;
            }

            // Same honesty the response half owes a schema-less header: a parameter documented with
            // `content`, or with nothing at all, is uncheckable rather than satisfied.
            if (! $parameter->hasSchema()) {
                $notes[] = isset($parameter->definition['content'])
                    ? sprintf('%s is documented as a content object, which the check does not decode', $parameter->label())
                    : sprintf('the contract documents no schema for %s', $parameter->label());

                continue;
            }

            foreach ($this->schema->check(
                ParameterValue::coerce($value, $parameter->schema()),
                $parameter->schemaSegments(),
                $parameter->label(),
            ) as $violation) {
                $violations[] = $violation;
            }
        }

        return $violations === [] ? Outcome::passed(self::note(...$notes)) : Outcome::failed($violations);
    }

    private function requestBody(ContractOperation $operation, Exchange $exchange): Outcome
    {
        $documented = $operation->requestBody($this->index->document());

        if ($documented === null) {
            return Outcome::passed();
        }

        [$body, $segments] = $documented;

        if (trim($exchange->requestBody) === '') {
            return ($body['required'] ?? false) === true
                ? Outcome::failed([Violation::ofExchange('sent no request body, which the contract documents as required', 'the request')])
                : Outcome::passed();
        }

        $content = $body['content'] ?? null;

        if (! is_array($content) || $content === []) {
            return Outcome::passed('the contract documents a request body with no media types');
        }

        /** @var array<string, mixed> $content */
        $requested = MediaType::base($exchange->requestContentType);
        $key = MediaType::select($content, $requested);

        if ($key === null) {
            return Outcome::failed([Violation::ofExchange(sprintf(
                'sent %s, which the contract does not document as a request body (it documents %s)',
                $requested ?? 'no content type',
                implode(', ', array_map(strval(...), array_keys($content))),
            ), 'the request')]);
        }

        return $this->body(
            $exchange->requestBody,
            $content[$key],
            [...$segments, 'content', $key, 'schema'],
            $key,
            'the request body',
            'the request',
        );
    }

    /**
     * Decode a JSON payload and check it against the media type's schema.
     *
     * @param  list<string>  $schemaSegments
     */
    private function body(string $raw, mixed $media, array $schemaSegments, string $mediaType, string $location, string $half): Outcome
    {
        if (! MediaType::isJson($mediaType)) {
            return Outcome::passed(sprintf('%s is %s, which JSON Schema cannot check', $location, $mediaType));
        }

        $schema = is_array($media) ? ($media['schema'] ?? null) : null;

        if (! is_array($schema) && ! is_bool($schema)) {
            return Outcome::passed(sprintf('the contract documents no schema for %s (%s)', $location, $mediaType));
        }

        if (trim($raw) === '') {
            return Outcome::failed([Violation::ofExchange(sprintf(
                '%s is empty, but the contract documents a %s body',
                $location,
                $mediaType,
            ), $half)]);
        }

        try {
            $data = json_decode($raw, false, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            return Outcome::failed([Violation::ofExchange(sprintf('%s is not valid JSON: %s', $location, $exception->getMessage()), $half)]);
        }

        return Outcome::failedOrPassed($this->schema->check($data, $schemaSegments, $location));
    }

    /**
     * One half's notes as the single note an {@see Outcome} carries. Several uncheckable things in one
     * half read as one sentence per finding rather than as one finding surviving.
     */
    private static function note(?string ...$notes): ?string
    {
        $kept = array_values(array_filter($notes, static fn (?string $note): bool => $note !== null));

        return $kept === [] ? null : implode('; ', $kept);
    }
}
