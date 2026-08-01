<?php

declare(strict_types=1);

namespace Docuccino\Core\Provenance;

/**
 * A project-root-relative source location. Never used as an identity input.
 */
final readonly class Source
{
    public function __construct(
        public string $file,
        public ?int $line = null,
        public ?string $symbol = null,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        $file = $data['file'] ?? '';
        $line = $data['line'] ?? null;
        $symbol = $data['symbol'] ?? null;

        return new self(
            file: is_string($file) ? $file : '',
            line: is_int($line) ? $line : null,
            symbol: is_string($symbol) ? $symbol : null,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $out = ['file' => $this->file];

        if ($this->line !== null) {
            $out['line'] = $this->line;
        }

        if ($this->symbol !== null) {
            $out['symbol'] = $this->symbol;
        }

        return $out;
    }
}
