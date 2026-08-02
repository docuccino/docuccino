<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Pipeline;

use Docuccino\Core\Diagnostics\Diagnostic;
use Docuccino\Core\Diagnostics\Severity;

/**
 * Collects build diagnostics and returns them in a deterministic order (design §5: never
 * time-based) — grouped by route signature, then by severity, code and message — so CLI output
 * and any `--embed-diagnostics` payload are byte-stable across runs.
 *
 * @internal
 */
final class DiagnosticBag
{
    /**
     * @var list<Diagnostic>
     */
    private array $diagnostics = [];

    public function add(Diagnostic $diagnostic): void
    {
        $this->diagnostics[] = $diagnostic;
    }

    /**
     * @param  iterable<Diagnostic>  $diagnostics
     */
    public function addAll(iterable $diagnostics): void
    {
        foreach ($diagnostics as $diagnostic) {
            $this->diagnostics[] = $diagnostic;
        }
    }

    /**
     * @return list<Diagnostic>
     */
    public function sorted(): array
    {
        $diagnostics = $this->diagnostics;

        usort($diagnostics, static function (Diagnostic $a, Diagnostic $b): int {
            return [$a->routeSignature ?? '', self::rank($a->severity), $a->code, $a->message]
                <=> [$b->routeSignature ?? '', self::rank($b->severity), $b->code, $b->message];
        });

        return $diagnostics;
    }

    public function has(Severity $severity): bool
    {
        foreach ($this->diagnostics as $diagnostic) {
            if ($diagnostic->severity === $severity) {
                return true;
            }
        }

        return false;
    }

    private static function rank(Severity $severity): int
    {
        return match ($severity) {
            Severity::Error => 0,
            Severity::Warning => 1,
            Severity::Info => 2,
            Severity::Hint => 3,
        };
    }
}
