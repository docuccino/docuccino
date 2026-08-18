<?php

declare(strict_types=1);

namespace Docuccino\Core\Examples;

use Docuccino\Core\Support\Json;

/**
 * One response body a test suite produced, kept as the example for a status and a media type.
 *
 * The shape is derived, never stored: a body and a fingerprint that disagreed would be a recording
 * that lies about when it needs re-recording.
 *
 * @internal
 */
final readonly class RecordedExample
{
    private function __construct(
        public string $status,
        public string $mediaType,
        public mixed $body,
    ) {}

    public static function of(string $status, string $mediaType, mixed $body): self
    {
        return new self($status, $mediaType, $body);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): ?self
    {
        $status = $data['status'] ?? null;
        $mediaType = $data['mediaType'] ?? null;

        if (! is_string($status) || $status === '' || ! is_string($mediaType) || $mediaType === '') {
            return null;
        }

        if (! array_key_exists('body', $data)) {
            return null;
        }

        return new self($status, $mediaType, $data['body']);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return ['status' => $this->status, 'mediaType' => $this->mediaType, 'body' => $this->body];
    }

    /** What this example is the example FOR — one per status and media type. */
    public function key(): string
    {
        return $this->status.' '.$this->mediaType;
    }

    public function shape(): string
    {
        return ResponseShape::of($this->body);
    }

    /**
     * Whether this body is the better illustration of the two.
     *
     * A suite produces many responses per operation and one of them has to be published, so the choice
     * has to be a function of the bodies themselves — pick by which arrived first and the published
     * example changes when someone reorders a test file. The most POPULATED body wins, because a
     * response with its optional members filled in shows a reader more of the contract; then the
     * shorter one, because a compact illustration reads better than a long one saying the same thing;
     * then the lexicographically smaller, which decides nothing but decides it the same way every run.
     */
    public function outranks(self $other): bool
    {
        return $this->rank() < $other->rank();
    }

    /**
     * @return array{0: int, 1: int, 2: string}
     */
    private function rank(): array
    {
        $encoded = Json::stable($this->body);

        return [-ResponseShape::populatedPaths($this->body), strlen($encoded), $encoded];
    }
}
