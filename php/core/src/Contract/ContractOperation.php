<?php

declare(strict_types=1);

namespace Docuccino\Core\Contract;

/**
 * One operation of the documented contract: its stable `x-docuccino.id`, the method and path template
 * it answers, and the raw OAS operation object.
 *
 * Path-item-level parameters are already merged into {@see $parameters} (an operation-level parameter
 * of the same name and location wins, as OAS requires), so a caller never has to remember the
 * inheritance rule.
 */
final readonly class ContractOperation
{
    private PathTemplate $template;

    /**
     * @param  string|null  $id  the `x-docuccino.id`, absent only when the artifact carries no identities
     * @param  string  $path  the path TEMPLATE, `/api/invoices/{invoice}`
     * @param  array<string, mixed>  $operation
     * @param  list<ContractParameter>  $parameters
     * @param  list<string>  $segments  document pointer segments addressing this operation
     */
    public function __construct(
        public ?string $id,
        public string $method,
        public string $path,
        public array $operation,
        public array $parameters,
        public array $segments,
    ) {
        $this->template = PathTemplate::parse($path);
    }

    /** `GET /api/invoices/{invoice}` — how a failure message and a coverage row name the operation. */
    public function label(): string
    {
        return $this->method.' '.$this->path;
    }

    /**
     * The path parameters a concrete request path binds to this template, or null when the template
     * does not describe that path at all.
     *
     * @return array<string, string>|null
     */
    public function bind(string $path): ?array
    {
        return $this->template->bind($path);
    }

    /**
     * How specific this template is, for choosing between two that both matched — `/api/invoices/recent`
     * beats `/api/invoices/{invoice}`. Comparable as a string against another matched template.
     *
     * @internal
     */
    public function literalMask(): string
    {
        return $this->template->literalMask();
    }

    /**
     * The documented response for a status code and the pointer segments that address it. A `$ref` into
     * `components/responses` is followed, so the segments name where a reader would actually go and look.
     *
     * @param  array<string, mixed>  $document
     * @return array{0: array<string, mixed>, 1: list<string>}|null
     */
    public function responseFor(array $document, int $status): ?array
    {
        $key = $this->responseKeyFor($status);

        if ($key === null) {
            return null;
        }

        /** @var array<string, mixed> $response */
        $response = $this->responses()[$key];

        return Refs::follow($document, $response, [...$this->segments, 'responses', $key]);
    }

    /**
     * Which documented response a status resolves to: the exact code first, then the OAS range (`2XX`),
     * then `default` — null when the contract documents no response this status could be.
     *
     * This is the operation's one status grammar. Everything that asks what a status was checked
     * against, or whether one was ever seen, reads it here, so a coverage row and a failure message can
     * never disagree about which response a 422 belonged to.
     */
    public function responseKeyFor(int $status): ?string
    {
        $responses = $this->responses();

        foreach ([(string) $status, substr((string) $status, 0, 1).'XX', 'default'] as $key) {
            if (is_array($responses[$key] ?? null)) {
                return $key;
            }
        }

        return null;
    }

    /**
     * The documented response keys, ordered exact codes ascending, then ranges, then anything else,
     * then `default` — a function of the key set alone, so two runs list them identically.
     *
     * @return list<string>
     */
    public function responseKeys(): array
    {
        $keys = [];
        foreach ($this->responses() as $key => $response) {
            if (is_array($response)) {
                $keys[] = (string) $key;
            }
        }

        usort($keys, static fn (string $a, string $b): int => [self::rank($a), self::code($a), $a] <=> [self::rank($b), self::code($b), $b]);

        return $keys;
    }

    /** The documented status keys, for a "the contract documents 200, 404" message. */
    public function documentedStatuses(): string
    {
        $keys = $this->responseKeys();

        return $keys === [] ? 'none' : implode(', ', $keys);
    }

    /**
     * The documented request body and the segments addressing it, `$ref` followed.
     *
     * @param  array<string, mixed>  $document
     * @return array{0: array<string, mixed>, 1: list<string>}|null
     */
    public function requestBody(array $document): ?array
    {
        $body = $this->operation['requestBody'] ?? null;

        if (! is_array($body)) {
            return null;
        }

        /** @var array<string, mixed> $body */
        return Refs::follow($document, $body, [...$this->segments, 'requestBody']);
    }

    /**
     * The `responses` map as written, or empty where the operation has none. Keys come back as the
     * document spelled them, which for `200` is an int — PHP normalises a numeric string key.
     *
     * @return array<array-key, mixed>
     */
    private function responses(): array
    {
        $responses = $this->operation['responses'] ?? null;

        return is_array($responses) ? $responses : [];
    }

    /** Which family a response key belongs to, lowest first: exact code, range, anything else, `default`. */
    private static function rank(string $key): int
    {
        return match (true) {
            preg_match('/^\d{3}$/D', $key) === 1 => 0,
            preg_match('/^\dXX$/Di', $key) === 1 => 1,
            $key === 'default' => 3,
            default => 2,
        };
    }

    /** The status a key sorts at within its family — `4XX` sorts where `400` would. */
    private static function code(string $key): int
    {
        if (preg_match('/^(\d)XX$/Di', $key, $matches) === 1) {
            return ((int) $matches[1]) * 100;
        }

        return preg_match('/^\d{3}$/D', $key) === 1 ? (int) $key : 0;
    }
}
