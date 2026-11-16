<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\ExportFault;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Response as HttpResponse;
use Illuminate\Support\Facades\Response;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Responses made through the `Response` facade and its root-namespace alias rather than `response()`: JSON
 * and no-content responses, plain and view responses, each with a status of its own; a status read off an
 * enum case, inline and through a helper handed the case or the status; and the file, stream and redirect
 * responses the same factory makes. Beside them, the root-namespace aliases of `DB` and `Auth` and one the
 * application adds itself (`\Reply`).
 */
class ResponseFacadeStatusController extends Controller
{
    public function json(): JsonResponse
    {
        return Response::json(['error' => 'locked'], 422);
    }

    public function jsonOk(): JsonResponse
    {
        return Response::json(['ok' => true]);
    }

    public function noContent(): HttpResponse
    {
        return Response::noContent(205);
    }

    public function make(): HttpResponse
    {
        return Response::make('', 410);
    }

    public function view(): HttpResponse
    {
        return Response::view('archived', [], 410);
    }

    public function alias(): JsonResponse
    {
        return \Response::json(['error' => 'locked'], 422);
    }

    public function aliasMade(): HttpResponse
    {
        return \Response::make('', 410);
    }

    public function appAlias(): JsonResponse
    {
        return \Reply::json(['error' => 'locked'], 422);
    }

    public function aliasDatabase(): JsonResponse
    {
        return response()->json(['archived' => \DB::table('exports')->count()]);
    }

    public function aliasAuth(): JsonResponse
    {
        return response()->json(['owner' => \Auth::id()]);
    }

    public function caseRead(): JsonResponse
    {
        return Response::json(['error' => 'locked'], ExportFault::Locked->value);
    }

    public function helperStatus(): JsonResponse
    {
        return $this->failed(409);
    }

    public function helperCase(): JsonResponse
    {
        return $this->faulted(ExportFault::Gone);
    }

    public function helperMade(): HttpResponse
    {
        return $this->made(ExportFault::Locked);
    }

    public function download(): BinaryFileResponse
    {
        return Response::download(storage_path('exports/report.csv'));
    }

    public function file(): BinaryFileResponse
    {
        return Response::file(storage_path('exports/report.pdf'));
    }

    public function stream(): StreamedResponse
    {
        return Response::stream(static function (): void {
            echo 'chunk';
        }, 206);
    }

    public function redirect(): RedirectResponse
    {
        return Response::redirectTo('/archive', 301);
    }

    protected function failed(int $status): JsonResponse
    {
        return Response::json(['error' => 'failed'], $status);
    }

    protected function faulted(ExportFault $fault): JsonResponse
    {
        return Response::json(['error' => $fault->name], $fault->value);
    }

    protected function made(ExportFault $fault): HttpResponse
    {
        return Response::make('', $fault->value);
    }
}
