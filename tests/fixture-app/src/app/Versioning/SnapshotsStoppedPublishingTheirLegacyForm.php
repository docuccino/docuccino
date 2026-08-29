<?php

declare(strict_types=1);

namespace App\Versioning;

use App\Data\SnapshotData;
use App\Data\SnapshotFormData;
use Docuccino\Attributes\Versioning\ApiVersionChange;
use Docuccino\Attributes\Versioning\RemovedResponseField;

/**
 * A removal whose type is a class the document already publishes — here one the analyser recovered out
 * of a `@var list<SnapshotFormData>` tag, enum member and all. Nothing about the shape is written down
 * twice: the declaration names the class, and the document's own component for it is what the older
 * version's field points at.
 */
#[ApiVersionChange(
    since: '2026-09-01',
    description: 'A snapshot no longer publishes the form zone it was created from.',
)]
#[RemovedResponseField(
    schema: SnapshotData::class,
    field: 'legacy_form',
    type: SnapshotFormData::class,
    description: 'The form zone this snapshot was created from.',
)]
final class SnapshotsStoppedPublishingTheirLegacyForm {}
