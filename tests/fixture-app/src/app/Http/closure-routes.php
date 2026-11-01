<?php

use App\Http\Resources\UserResource;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

/*
 * Real-engine fixture: a routes file of closure actions, each response written twice — as a full closure
 * and as the arrow function that means the same thing, `function () use (…) { return …; }`. Nothing boots;
 * the tests analyse this file at the line each closure's keyword is written on.
 */

$greeting = 'hello';

Route::get('/closure/json', function (Request $request) {
    return response()->json(['id' => 1, 'name' => $request->string('name')->toString()]);
});
Route::get('/arrow/json', fn (Request $request) => response()->json(['id' => 1, 'name' => $request->string('name')->toString()]));

Route::get('/closure/resource/{id}', function (int $id) {
    return new UserResource(User::findOrFail($id));
});
Route::get('/arrow/resource/{id}', fn (int $id) => new UserResource(User::findOrFail($id)));

Route::get('/closure/view', function () {
    return view('welcome');
});
Route::get('/arrow/view', fn () => view('welcome'));

Route::get('/closure/abort', function () {
    return abort(403);
});
Route::get('/arrow/abort', fn () => abort(403));

Route::get('/closure/captured', function () use ($greeting) {
    return response()->json(['greeting' => $greeting]);
});
Route::get('/arrow/captured', fn () => response()->json(['greeting' => $greeting]));

Route::get('/closure/static', static function () {
    return response()->json(['kind' => 'static'], 201);
});
Route::get('/arrow/static', static fn () => response()->json(['kind' => 'static'], 201));

Route::get('/closure/nested', function () {
    return response()->json(['items' => array_map(
        fn (int $n): array => ['n' => $n],
        [1, 2],
    )]);
});
Route::get('/arrow/nested', fn () => response()->json(['items' => array_map(
    fn (int $n): array => ['n' => $n],
    [1, 2],
)]));

Route::get('/closure/throws', function (Request $request) {
    return $request->user() ?? throw new \Illuminate\Auth\AuthenticationException;
});
Route::get('/arrow/throws', fn (Request $request) => $request->user() ?? throw new \Illuminate\Auth\AuthenticationException);

// Two closures on one line: reflection's line names both.
Route::get('/arrow/one-line', fn () => response()->json(array_map(fn (int $n): int => $n, [1])));
