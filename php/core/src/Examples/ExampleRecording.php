<?php

declare(strict_types=1);

namespace Docuccino\Core\Examples;

/**
 * Every example recorded for one operation, keyed by the operation's stable `x-docuccino.id`.
 *
 * The id is the key rather than the path, so renaming a route carries its recordings with it — and a
 * change that really does make a different operation leaves the old file behind, where
 * {@see RecordedExampleAudit} reports it rather than letting it quietly go on being published.
 *
 * @internal
 */
final readonly class ExampleRecording
{
    /** The format marker written into every file, so a reader can refuse one it does not know. */
    public const string FORMAT = 'recording/1';

    /**
     * @param  list<RecordedExample>  $responses  sorted by {@see RecordedExample::key()}
     */
    private function __construct(
        public string $operationId,
        public string $endpoint,
        public array $responses,
    ) {}

    /**
     * @param  list<RecordedExample>  $responses
     */
    public static function of(string $operationId, string $endpoint, array $responses = []): self
    {
        return new self($operationId, $endpoint, self::sorted($responses));
    }

    /**
     * The recording a decoded file describes, or null when the file is not one — an unknown format
     * marker, a missing operation id, or responses that are not response entries.
     *
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): ?self
    {
        if (($data['docuccino'] ?? null) !== self::FORMAT) {
            return null;
        }

        $operationId = $data['operation'] ?? null;
        $endpoint = $data['endpoint'] ?? null;
        $responses = $data['responses'] ?? null;

        if (! is_string($operationId) || $operationId === '' || ! is_array($responses)) {
            return null;
        }

        $examples = [];
        foreach ($responses as $entry) {
            if (! is_array($entry)) {
                return null;
            }

            /** @var array<string, mixed> $entry */
            $example = RecordedExample::fromArray($entry);
            if ($example === null) {
                return null;
            }

            $examples[] = $example;
        }

        return new self($operationId, is_string($endpoint) ? $endpoint : '', self::sorted($examples));
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'docuccino' => self::FORMAT,
            'operation' => $this->operationId,
            'endpoint' => $this->endpoint,
            'responses' => array_map(static fn (RecordedExample $e): array => $e->toArray(), $this->responses),
        ];
    }

    public function find(string $status, string $mediaType): ?RecordedExample
    {
        foreach ($this->responses as $response) {
            if ($response->status === $status && $response->mediaType === $mediaType) {
                return $response;
            }
        }

        return null;
    }

    /**
     * This recording with $example taking its status and media type — unless what is already there has
     * the same SHAPE, in which case the committed bytes stand.
     *
     * That single rule is what keeps a recording out of the artifact's churn. A created-at timestamp, a
     * UUID or an autoincrement key moves the body on every run and the shape on none of them, so
     * re-recording a suite that has not changed rewrites nothing at all. When the structure really does
     * move the file moves with it, which is exactly the change an author should be reading in the diff.
     */
    public function with(RecordedExample $example): self
    {
        $existing = $this->find($example->status, $example->mediaType);

        if ($existing !== null && $existing->shape() === $example->shape()) {
            return $this;
        }

        $responses = array_values(array_filter(
            $this->responses,
            static fn (RecordedExample $e): bool => $e->key() !== $example->key(),
        ));
        $responses[] = $example;

        return new self($this->operationId, $this->endpoint, self::sorted($responses));
    }

    /** The same recording under a new endpoint label, which is prose for the reviewer and nothing else. */
    public function labelled(string $endpoint): self
    {
        return $endpoint === $this->endpoint ? $this : new self($this->operationId, $endpoint, $this->responses);
    }

    /**
     * @param  list<RecordedExample>  $responses
     * @return list<RecordedExample>
     */
    private static function sorted(array $responses): array
    {
        usort($responses, static fn (RecordedExample $a, RecordedExample $b): int => strcmp($a->key(), $b->key()));

        return $responses;
    }
}
