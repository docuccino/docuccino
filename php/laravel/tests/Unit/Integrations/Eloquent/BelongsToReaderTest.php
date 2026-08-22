<?php

declare(strict_types=1);

use Docuccino\Laravel\Integrations\Eloquent\BelongsToReader;
use Docuccino\Laravel\Tests\Fixtures\Eloquent\Codex;
use Docuccino\Laravel\Tests\Fixtures\Eloquent\FilterCastModel;
use Docuccino\Laravel\Tests\Fixtures\Eloquent\FilterRelationModel;
use Docuccino\Laravel\Tests\Fixtures\Eloquent\Vault;
use Docuccino\Laravel\Tests\Fixtures\Eloquent\Waybill;

/**
 * The static `belongsTo` reader: literal arguments (positional, named, an explicit `null`, a
 * relation-name override) are recovered, and anything unreadable — a non-literal argument, a
 * `morphTo`, a non-model target, a missing file — is omitted rather than guessed.
 */
it('reads every literal belongsTo relation off the fixture model', function (): void {
    $relations = (new BelongsToReader)->relations(FilterRelationModel::class);

    $byRelation = [];
    foreach ($relations as $relation) {
        $byRelation[$relation['relation']] = $relation;
    }

    // The full readable set — `dynamic` (non-literal fk) and `attachable` (morphTo) are omitted, and
    // `legacyArchive` appears under its literal relation-name argument. A shrunk set means the reader
    // stopped seeing shapes it must read.
    expect(array_keys($byRelation))->toBe([
        'vault', 'vaultKeeper', 'waybill', 'owner', 'keeper', 'sibling',
        'reference', 'opaque', 'codex', 'sealed', 'archive', 'first', 'second',
    ]);

    expect($byRelation['vault'])->toBe(['relation' => 'vault', 'related' => Vault::class, 'foreignKey' => null, 'ownerKey' => null])
        ->and($byRelation['keeper'])->toBe(['relation' => 'keeper', 'related' => Waybill::class, 'foreignKey' => 'named_keeper_id', 'ownerKey' => null])
        ->and($byRelation['reference'])->toBe(['relation' => 'reference', 'related' => FilterCastModel::class, 'foreignKey' => 'reference_key', 'ownerKey' => 'quantity'])
        ->and($byRelation['archive'])->toBe(['relation' => 'archive', 'related' => Vault::class, 'foreignKey' => null, 'ownerKey' => null])
        ->and($byRelation['codex'])->toBe(['relation' => 'codex', 'related' => Codex::class, 'foreignKey' => null, 'ownerKey' => null]);
});

it('returns nothing for a model with no belongsTo relations', function (): void {
    expect((new BelongsToReader)->relations(Vault::class))->toBe([]);
});

it('returns nothing for a class that is not loadable', function (): void {
    expect((new BelongsToReader)->relations('Not\\A\\Model'))->toBe([]);
});

it('returns nothing for a model whose declaration has no file to parse', function (): void {
    eval('namespace BelongsToReaderTestEval; final class FileslessModel extends \Illuminate\Database\Eloquent\Model { public function vault(): \Illuminate\Database\Eloquent\Relations\BelongsTo { return $this->belongsTo(\Docuccino\Laravel\Tests\Fixtures\Eloquent\Vault::class); } }');

    expect((new BelongsToReader)->relations('BelongsToReaderTestEval\\FileslessModel'))->toBe([]);
});
