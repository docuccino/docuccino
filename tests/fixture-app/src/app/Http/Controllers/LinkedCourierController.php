<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Data\CourierData;
use App\Models\Courier;
use App\Models\Shipment;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Spatie\LaravelData\Data;

/**
 * Builds one Data class from a model in each of the ways an action does — `from()` a model, `collect()`
 * models, the constructor by name and by position, a `toDTO()` the model writes, `from()` an array — and
 * from things that are not one model's column: either of two models, a find() that may be null, the request,
 * an object whose envelope the action then changes, and one a helper typed on the base class changes.
 */
class LinkedCourierController
{
    public function fromModel(Courier $courier): CourierData
    {
        return CourierData::from($courier);
    }

    /**
     * @return Collection<int, CourierData>
     */
    public function collected(): Collection
    {
        return CourierData::collect(Courier::query()->get());
    }

    public function named(Courier $courier): CourierData
    {
        return new CourierData(id: $courier->id, code: $courier->code, name: $courier->name);
    }

    public function positional(Courier $courier): CourierData
    {
        return new CourierData($courier->id, $courier->code, 'Unnamed');
    }

    public function handedOut(Courier $courier): CourierData
    {
        return $courier->toDTO();
    }

    public function payload(Courier $courier): CourierData
    {
        return CourierData::from(['id' => $courier->id, 'code' => $courier->code, 'name' => $courier->name]);
    }

    public function either(bool $flag): CourierData
    {
        return CourierData::from($flag ? Courier::query()->firstOrFail() : Shipment::query()->firstOrFail());
    }

    public function maybe(): CourierData
    {
        $courier = Courier::query()->find(1);

        return new CourierData(id: $courier->id, code: $courier->code, name: 'Unnamed');
    }

    public function requested(Request $request): CourierData
    {
        return new CourierData(id: $request->id, code: $request->code, name: 'Unnamed');
    }

    public function unwrapped(Courier $courier): CourierData
    {
        return CourierData::from($courier)->withoutWrapping();
    }

    public function described(Courier $courier): CourierData
    {
        return $this->withMeta(CourierData::from($courier));
    }

    /**
     * @template T of Data
     *
     * @param  T  $data
     * @return T
     */
    private function withMeta(Data $data): Data
    {
        return $data->additional(['code' => 'n/a']);
    }
}
