<?php

declare(strict_types=1);

namespace Docuccino\Core\Diagnostics;

use Docuccino\Core\Provenance\Source;
use Docuccino\Core\Support\Hydrate;
use Docuccino\Core\Support\PlainText;

/**
 * A single build diagnostic. The CLI is the primary channel; these are embedded in the
 * UIR document only under an explicit flag. Ordering is deterministic (never time-based).
 *
 * `code`, `message` and `help` are made safe to print HERE, once, and no producer owes it. All three
 * are stated around text somebody else chose — a route, a class, a config key, a message something
 * threw, and a code an extension names for itself — and a diagnostic has more than one destination.
 * The emitted document is the one that settles where the escaping lives: it is written with
 * `JSON_UNESCAPED_UNICODE`, so a direction override or a C1 control survives `json_encode` whole and
 * reaches whoever opens the artifact, with no render boundary of ours in between. Left to the producers
 * this is one invariant restated at every construction site, and a site that forgets it says nothing.
 * {@see PlainText} is idempotent, so a producer that escapes anyway is harmless, and
 * {@see fromArray()} — how a diagnostic comes back off a warm fragment-cache hit — arrives through this
 * constructor unchanged.
 *
 * `help` goes through {@see PlainText::lines()} rather than {@see PlainText::of()}: its line breaks are
 * layout a console writer indents, and a newline is the one control character every destination here
 * handles safely on its own.
 *
 * `routeSignature` is deliberately left as it was given. It is a key rather than a sentence — sorted
 * on, substituted, and compared against the signature a live route answers with — so escaping it here
 * would only make the two sides disagree; a signature is owed neutralising where it is minted, so that
 * both sides move together. `source` is a {@see Source}, shared with the provenance trail the whole
 * document carries, and belongs to that class for the same reason.
 *
 * A terminal has a second hazard on top of this one, its own markup, which nothing but the console
 * renderer knows about; that half stays at the render boundary.
 */
final readonly class Diagnostic
{
    public string $code;

    public string $message;

    public ?string $help;

    public function __construct(
        public Severity $severity,
        string $code,
        string $message,
        public ?Source $source = null,
        public ?string $routeSignature = null,
        ?string $help = null,
    ) {
        $this->code = PlainText::of($code);
        $this->message = PlainText::of($message);
        $this->help = $help === null ? null : PlainText::lines($help);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        $severity = $data['severity'] ?? Severity::Info->value;
        $code = $data['code'] ?? '';
        $message = $data['message'] ?? '';

        return new self(
            severity: is_string($severity)
                ? (Severity::tryFrom($severity) ?? Severity::Info)
                : Severity::Info,
            code: is_string($code) ? $code : '',
            message: is_string($message) ? $message : '',
            source: Hydrate::objectOrNull($data['source'] ?? null, Source::fromArray(...)),
            routeSignature: Hydrate::stringOrNull($data['routeSignature'] ?? null),
            help: Hydrate::stringOrNull($data['help'] ?? null),
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
