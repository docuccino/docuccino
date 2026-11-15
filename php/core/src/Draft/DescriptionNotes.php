<?php

declare(strict_types=1);

namespace Docuccino\Core\Draft;

use Docuccino\Core\Patch\Contribution;
use Docuccino\Core\Patch\PatchGuard;

/**
 * Sentences producers add beneath whatever description wins, rather than contesting the description
 * itself. For a fact the build proved: it is true under any description an author writes, so a higher
 * layer's prose must not erase it, and it must not displace that prose either. Joined after the winning
 * description in the order declared, with {@see DescriptionAppender}'s separator.
 *
 * @internal Held by the drafts that publish a description; producers declare through those drafts.
 */
final class DescriptionNotes
{
    /** @var list<array{0: string, 1: Contribution}> */
    private array $notes = [];

    public function declare(string $note, Contribution $by): void
    {
        $this->notes[] = [$note, $by];
    }

    /** The winning description with every note beneath it, or the notes alone where nothing won. */
    public function joinedTo(?string $description): ?string
    {
        foreach ($this->notes as [$note]) {
            $description = DescriptionAppender::joined($description, $note);
        }

        return $description;
    }

    /**
     * Whoever wrote a note, as a {@see PatchGuard::provenance()} `$also` entry: each wrote part of the
     * published description.
     *
     * @return array<string, list<Contribution>>
     */
    public function provenance(): array
    {
        $by = [];
        foreach ($this->notes as [, $contribution]) {
            $by[$contribution->recordKey()] = $contribution;
        }

        return $by === [] ? [] : ['description' => array_values($by)];
    }
}
