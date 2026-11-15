<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Links;

use Docuccino\Core\Extensions\Context\DocumentContext;
use Docuccino\Core\Extensions\Contracts\DocumentTransformer;
use Docuccino\Core\Extensions\Contracts\RouteNoteCollector;
use Docuccino\Core\Extensions\Document\UirDocumentDraft;
use Docuccino\Core\Extensions\Ordering\ExtensionOrder;
use Docuccino\Core\Extensions\Ordering\Priorities;
use Docuccino\Core\Links\OperationLinks;
use Docuccino\Core\Links\ResponseLinks;

/**
 * Turns what each operation recorded about its links ({@see ResponseLinkFacts}) into response `links`,
 * through core's {@see ResponseLinks}. The observation rides the fragment and the join does not, so a warm
 * build writes the links a cold one does.
 *
 * Late, after API versioning, so a link never names an operation an older version does not serve, nor a
 * member it renamed: the join reads the document that will be emitted.
 *
 * @internal
 */
#[ExtensionOrder(priority: Priorities::LATE)]
final class ResponseLinkAssembly implements DocumentTransformer, RouteNoteCollector
{
    /** @var array<string, list<string>> signature → the facts its routes recorded */
    private array $recorded = [];

    public function channel(): string
    {
        return ResponseLinkFacts::CHANNEL;
    }

    public function forget(): void
    {
        $this->recorded = [];
    }

    public function collect(string $key, array $values): void
    {
        foreach ($values as $value) {
            $this->recorded[$key][] = $value;
        }
    }

    public function transform(UirDocumentDraft $document, DocumentContext $context): void
    {
        if ($this->recorded === []) {
            return;
        }

        $facts = [];
        foreach ($this->recorded as $signature => $values) {
            $facts[$signature] = self::merged(array_map(OperationLinks::fromJson(...), $values));
        }

        [$doc, $diagnostics] = ResponseLinks::write($document->toArray(), $facts);

        foreach ($diagnostics as $diagnostic) {
            $context->report($diagnostic);
        }

        $document->replace($doc);
    }

    /**
     * One operation's facts from the notes its routes left — more than one where two routes share a
     * method and path, which the assembly has already reported as a contested slot.
     *
     * @param  list<OperationLinks>  $notes
     */
    private static function merged(array $notes): OperationLinks
    {
        $takes = $gives = $declared = $unreadable = [];

        foreach ($notes as $note) {
            $takes += $note->takes;
            array_push($gives, ...$note->gives);
            array_push($declared, ...$note->declared);
            array_push($unreadable, ...$note->unreadable);
        }

        return new OperationLinks($takes, $gives, $declared, $unreadable);
    }
}
