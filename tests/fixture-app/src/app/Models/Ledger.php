<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A model whose `newCollection()` keys every collection of it by id, as an application writes one to look
 * rows up by key: no return type, so its type is the framework's collection, whatever keys the body gives.
 * Only ever reflected — never queried.
 *
 * @property int $id        The entry identifier.
 * @property string $memo   A free-text memo.
 */
class Ledger extends Model
{
    public function newCollection(array $models = [])
    {
        return parent::newCollection($models)->keyBy('id');
    }
}
