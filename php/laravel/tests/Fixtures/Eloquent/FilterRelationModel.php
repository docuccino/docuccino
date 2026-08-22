<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Tests\Fixtures\Eloquent;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * A model fixture whose `belongsTo` relations cover every shape the filter foreign-key hop reads:
 * default and explicit foreign keys, named arguments, owner keys, a renamed related primary key — and
 * the refusals: a non-literal argument, a `morphTo`, two relations contesting one column. Only ever
 * reflected — never queried.
 */
final class FilterRelationModel extends Model
{
    private const DYNAMIC_KEY = 'dynamic_id';

    /**
     * Default foreign key (`vault_id`) to a uuid-keyed model.
     *
     * @return BelongsTo<Vault, $this>
     */
    public function vault(): BelongsTo
    {
        return $this->belongsTo(Vault::class);
    }

    /**
     * A camelCase method — the default foreign key snake-cases it (`vault_keeper_id`).
     *
     * @return BelongsTo<Vault, $this>
     */
    public function vaultKeeper(): BelongsTo
    {
        return $this->belongsTo(Vault::class);
    }

    /**
     * A chained modifier — the one `belongsTo` inside still reads (`waybill_id`, ulid).
     *
     * @return BelongsTo<Waybill, $this>
     */
    public function waybill(): BelongsTo
    {
        return $this->belongsTo(Waybill::class)->withDefault();
    }

    /**
     * An explicit foreign key as the second argument.
     *
     * @return BelongsTo<Vault, $this>
     */
    public function owner(): BelongsTo
    {
        return $this->belongsTo(Vault::class, 'custom_owner_id');
    }

    /**
     * An explicit foreign key as a NAMED argument.
     *
     * @return BelongsTo<Waybill, $this>
     */
    public function keeper(): BelongsTo
    {
        return $this->belongsTo(Waybill::class, foreignKey: 'named_keeper_id');
    }

    /**
     * Default foreign key (`sibling_id`) to a default int-keyed model.
     *
     * @return BelongsTo<FilterCastModel, $this>
     */
    public function sibling(): BelongsTo
    {
        return $this->belongsTo(FilterCastModel::class);
    }

    /**
     * An owner key naming a CAST column on the related model (`quantity`, integer).
     *
     * @return BelongsTo<FilterCastModel, $this>
     */
    public function reference(): BelongsTo
    {
        return $this->belongsTo(FilterCastModel::class, 'reference_key', 'quantity');
    }

    /**
     * An owner key naming an uncast, non-key column — nothing types it.
     *
     * @return BelongsTo<FilterCastModel, $this>
     */
    public function opaque(): BelongsTo
    {
        return $this->belongsTo(FilterCastModel::class, 'opaque_key', 'untyped_column');
    }

    /**
     * Default foreign key to a model whose primary key is renamed (`codex_guid`, uuid).
     *
     * @return BelongsTo<Codex, $this>
     */
    public function codex(): BelongsTo
    {
        return $this->belongsTo(Codex::class);
    }

    /**
     * An owner key naming a custom-cast column — the caster owns the wire form, so nothing types it.
     *
     * @return BelongsTo<FilterCastModel, $this>
     */
    public function sealed(): BelongsTo
    {
        return $this->belongsTo(FilterCastModel::class, 'sealed_key', 'custom');
    }

    /**
     * A literal relation-name argument (and explicit `null` defaults) — the default foreign key
     * snake-cases the NAME, not the method (`archive_id`).
     *
     * @return BelongsTo<Vault, $this>
     */
    public function legacyArchive(): BelongsTo
    {
        return $this->belongsTo(Vault::class, null, null, 'archive');
    }

    /**
     * A non-literal foreign-key argument — the relation can't be read.
     *
     * @return BelongsTo<Vault, $this>
     */
    public function dynamic(): BelongsTo
    {
        return $this->belongsTo(Vault::class, self::DYNAMIC_KEY);
    }

    /**
     * A polymorphic relation — its `attachable_id` column references no single model.
     *
     * @return MorphTo<Model, $this>
     */
    public function attachable(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * One of two relations declaring the SAME explicit foreign key (`shared_id`).
     *
     * @return BelongsTo<Vault, $this>
     */
    public function first(): BelongsTo
    {
        return $this->belongsTo(Vault::class, 'shared_id');
    }

    /**
     * The other `shared_id` contestant — the contest has no single truthful answer.
     *
     * @return BelongsTo<FilterCastModel, $this>
     */
    public function second(): BelongsTo
    {
        return $this->belongsTo(FilterCastModel::class, 'shared_id');
    }
}
