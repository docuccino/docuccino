<?php

declare(strict_types=1);

namespace App\Versioning;

use App\Data\SnapshotData;
use Docuccino\Attributes\Versioning\ApiVersionChange;
use Docuccino\Attributes\Versioning\MadeResponseFieldRequired;
use Docuccino\Attributes\Versioning\RenamedResponseField;

/**
 * Two verbs over members the analyser recovered rather than members anybody wrote into a schema by
 * hand: `candidate` carries the free-form map and the `@example` its own docblock states, and
 * `permissions` is required because the recovered type says it cannot be null.
 */
#[ApiVersionChange(
    since: '2026-12-01',
    description: 'A snapshot publishes `candidate` where it published `applicant`, and always sends its permissions.',
)]
#[MadeResponseFieldRequired(schema: SnapshotData::class, field: 'permissions')]
#[RenamedResponseField(schema: SnapshotData::class, from: 'applicant', to: 'candidate')]
final class SnapshotsRenamedTheirCandidate {}
