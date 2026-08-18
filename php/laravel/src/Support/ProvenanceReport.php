<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Support;

use Docuccino\Core\Patch\Layer;
use Docuccino\Core\Provenance\Explain\ExplainedNode;
use Docuccino\Core\Provenance\Explain\FieldContribution;
use Docuccino\Core\Provenance\Explain\FieldTrail;
use Docuccino\Core\Provenance\Source;

/**
 * Renders a provenance trail as a stack per field: the value the document publishes on top, then
 * every value a lower rung tried and lost with. One colour and one written-out name per precedence
 * layer, used identically everywhere, so a reader learns the ladder off one screen.
 *
 * Colour never carries meaning on its own — a mark, an indent and the layer's NAME say the same
 * thing — so the report reads the same piped to a file, under `--no-ansi` and in a CI log.
 *
 * Nothing here consults the terminal width: the same document renders the same bytes on every
 * machine, which is what makes the output snapshot-testable. Long values are elided against a fixed
 * budget instead, and a `file:line` is never elided or wrapped, because opening it is the reader's
 * next move.
 *
 * @internal
 */
final class ProvenanceReport
{
    /** Wide enough for `integration`, the longest layer name, so the marks line up under each other. */
    private const int LAYER_WIDTH = 11;

    /** How much of a value is worth showing before it stops being scannable. */
    private const int VALUE_WIDTH = 56;

    /**
     * A mapper reports 0.9 for a conversion that fully succeeded, which is most of them, so printing
     * every number trains a reader to skip the column. Only a value BELOW it says something.
     */
    private const float CONFIDENCE_FLOOR = 0.9;

    /**
     * The ladder, low to high, with the mark vocabulary under it — the mental model a reader is
     * usually missing when they ask why an endpoint is documented the way it is.
     *
     * @return list<string>
     */
    public function legend(): array
    {
        $rungs = array_map(
            static fn (Layer $layer): string => sprintf('<fg=%s>%s</>', self::colour($layer), $layer->label()),
            Layer::cases(),
        );

        return [
            '<fg=gray>Precedence, low to high — the highest rung that writes a field wins it:</>',
            implode('<fg=gray> › </>', $rungs),
            '<fg=green>✓</> published    <fg=gray>✗ shadowed</>',
        ];
    }

    /**
     * @param  list<ExplainedNode>  $nodes
     * @return list<string>
     */
    public function lines(array $nodes): array
    {
        $lines = [];

        foreach ($nodes as $node) {
            $lines[] = '';
            $lines[] = $this->nodeLine($node);

            // One integration usually writes a whole parameter or response from one place, and
            // repeating that line under every field of it buries the fields. Where the node has
            // exactly one story to tell, it is told once at the top instead.
            $shared = $this->sharedDetail($node);
            if ($shared !== null) {
                $lines[] = sprintf('  <fg=gray>from %s</>', $shared);
            }

            foreach ($node->fields as $trail) {
                $lines = [...$lines, ...$this->trailLines($trail, $shared !== null)];
            }
        }

        return $lines;
    }

    /**
     * One line counting what the reader just read, so a long report ends somewhere.
     *
     * @param  list<ExplainedNode>  $nodes
     */
    public function summary(array $nodes): string
    {
        $fields = 0;
        $contributions = 0;
        $shadowed = 0;

        foreach ($nodes as $node) {
            foreach ($node->fields as $trail) {
                $fields++;
                foreach ($trail->contributions as $contribution) {
                    $contributions++;
                    $shadowed += $contribution->won ? 0 : 1;
                }
            }
        }

        return sprintf(
            '<fg=gray>%d field%s · %d contribution%s · %d shadowed</>',
            $fields,
            $fields === 1 ? '' : 's',
            $contributions,
            $contributions === 1 ? '' : 's',
            $shadowed,
        );
    }

    private function nodeLine(ExplainedNode $node): string
    {
        $line = sprintf('<options=bold>%s</>', TerminalText::of($node->label));

        return $node->ref === null
            ? $line
            : $line.sprintf('  <fg=gray>→ %s</>', TerminalText::of($node->ref));
    }

    /**
     * The one detail line every contribution on this node shares, or null when they differ — in which
     * case each keeps its own.
     */
    private function sharedDetail(ExplainedNode $node): ?string
    {
        $details = [];

        foreach ($node->fields as $trail) {
            foreach ($trail->contributions as $contribution) {
                $details[] = $this->detail($contribution) ?? '';
            }
        }

        $unique = array_values(array_unique($details));

        return count($unique) === 1 && $unique[0] !== '' && count($details) > 1 ? $unique[0] : null;
    }

    /**
     * @return list<string>
     */
    private function trailLines(FieldTrail $trail, bool $hoisted): array
    {
        $lines = ['  '.TerminalText::of($trail->field)];

        foreach ($trail->contributions as $contribution) {
            $lines[] = $this->contributionLine($contribution);

            $detail = $hoisted ? null : $this->detail($contribution);
            if ($detail !== null) {
                $lines[] = sprintf('        <fg=gray>%s</>', $detail);
            }
        }

        return $lines;
    }

    private function contributionLine(FieldContribution $contribution): string
    {
        $value = self::value($contribution->value);

        return sprintf(
            '    %s <fg=%s>%s</> %s',
            $contribution->won ? '<fg=green>✓</>' : '<fg=gray>✗</>',
            self::colour($contribution->layer),
            str_pad($contribution->layer->label(), self::LAYER_WIDTH),
            $contribution->won ? TerminalText::of($value) : sprintf('<fg=gray>%s</>', TerminalText::of($value)),
        );
    }

    /**
     * Where the contribution came from, when there is anything to add: the producer where it names
     * something more specific than its rung, the `file:line` to open, and a confidence only where it
     * is low enough to act on.
     */
    private function detail(FieldContribution $contribution): ?string
    {
        $parts = [];

        if ($contribution->producer !== $contribution->layer->label()) {
            $parts[] = TerminalText::of($contribution->producer);
        }

        $where = self::where($contribution->source);
        if ($where !== null) {
            $parts[] = $where;
        }

        if ($contribution->confidence !== null && $contribution->confidence < self::CONFIDENCE_FLOOR) {
            $parts[] = sprintf('confidence %s', rtrim(rtrim(number_format($contribution->confidence, 2, '.', ''), '0'), '.'));
        }

        return $parts === [] ? null : implode(' · ', $parts);
    }

    /**
     * `file:line`, then the symbol only where it says something the path does not — a source inside
     * a parent class or a trait, or a pseudo-symbol like `implicit:validated-request`. A
     * `FooController::bar` beside `.../FooController.php` is the path again, and dropping it is what
     * keeps the line short enough to stay one line.
     */
    private static function where(?Source $source): ?string
    {
        if ($source === null || $source->file === '') {
            return null;
        }

        $where = $source->file.($source->line === null ? '' : ':'.$source->line);
        $symbol = $source->symbol;

        if ($symbol !== null && ! str_starts_with($symbol, basename($source->file, '.php').'::') && ! str_contains($symbol, '\\'.basename($source->file, '.php').'::')) {
            $where .= ' · '.$symbol;
        }

        return TerminalText::of($where);
    }

    /** A value as one scannable line: JSON, so a string reads as a string, elided to a fixed budget. */
    private static function value(mixed $value): string
    {
        if ($value === null) {
            return '(not on this node)';
        }

        $encoded = json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ($encoded === false) {
            return '('.gettype($value).')';
        }

        return mb_strlen($encoded) <= self::VALUE_WIDTH
            ? $encoded
            : mb_substr($encoded, 0, self::VALUE_WIDTH - 1).'…';
    }

    /** The one palette. Written out beside the layer's NAME everywhere, never as the only signal. */
    private static function colour(Layer $layer): string
    {
        return match ($layer) {
            Layer::Fallback => 'gray',
            Layer::Inference => 'cyan',
            Layer::Integration => 'bright-blue',
            Layer::Docblock => 'green',
            Layer::Attribute => 'yellow',
            Layer::Overlay => 'magenta',
            Layer::Config => 'bright-red',
        };
    }
}
