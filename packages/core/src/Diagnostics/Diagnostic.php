<?php

declare(strict_types=1);

namespace Docuccino\Core\Diagnostics;

use Docuccino\Core\Provenance\Source;
use Docuccino\Core\Support\Arr;

/**
 * A single build diagnostic. The CLI is the primary channel; these are embedded in the
 * UIR document only under an explicit flag. Ordering is deterministic (never time-based).
 */
final readonly class Diagnostic
{
    public function __construct(
        public Severity $severity,
        public string $code,
        public string $message,
        public ?Source $source = null,
        public ?string $routeSignature = null,
        public ?string $help = null,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        $severity = $data['severity'] ?? Severity::Info->value;
        $code = $data['code'] ?? '';
        $message = $data['message'] ?? '';
        $routeSignature = $data['routeSignature'] ?? null;
        $help = $data['help'] ?? null;

        return new self(
            severity: is_string($severity)
                ? (Severity::tryFrom($severity) ?? Severity::Info)
                : Severity::Info,
            code: is_string($code) ? $code : '',
            message: is_string($message) ? $message : '',
            source: isset($data['source']) && is_array($data['source'])
                ? Source::fromArray(Arr::stringKeyed($data['source']))
                : null,
            routeSignature: is_string($routeSignature) ? $routeSignature : null,
            help: is_string($help) ? $help : null,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $out = [
            'severity' => $this->severity->value,
            'code' => $this->code,
            'message' => $this->message,
        ];

        if ($this->source !== null) {
            $out['source'] = $this->source->toArray();
        }

        if ($this->routeSignature !== null) {
            $out['routeSignature'] = $this->routeSignature;
        }

        if ($this->help !== null) {
            $out['help'] = $this->help;
        }

        return $out;
    }
}
