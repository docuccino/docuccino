<?php

declare(strict_types=1);

namespace Workbench\App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response as HttpResponse;
use Illuminate\Support\Facades\Response;
use Workbench\App\Enums\ExportFault;

/**
 * Responses made through the `Response` facade, each sent with the status its call names: JSON, no-content,
 * plain and view responses, one whose status a helper reads off the case it is handed, and one sent with
 * the framework's 200; and the same calls through the facade's root-namespace alias and an application's own.
 */
final class ArchiveFacadeController
{
    /** Refuse to change a locked archive. */
    public function refuse(): JsonResponse
    {
        return Response::json(['error' => 'locked'], 422);
    }

    /** Reset the archive view. */
    public function reset(): HttpResponse
    {
        return Response::noContent(205);
    }

    /** Purge an archived entry. */
    public function purge(): HttpResponse
    {
        return Response::make('', 410);
    }

    /** Show the tombstone of an archived entry. */
    public function tombstone(): HttpResponse
    {
        return Response::view('archived', [], 410);
    }

    /** Expire an archived entry. */
    public function expire(): JsonResponse
    {
        return $this->faulted(ExportFault::Gone);
    }

    /** Report the archive's status. */
    public function status(): JsonResponse
    {
        return Response::json(['ok' => true]);
    }

    /** Refuse to change a locked archive, through the root-namespace alias. */
    public function refuseAliased(): JsonResponse
    {
        return \Response::json(['error' => 'locked'], 422);
    }

    /** Purge an archived entry, through the root-namespace alias. */
    public function purgeAliased(): HttpResponse
    {
        return \Response::make('', 410);
    }

    /** Refuse to change a locked archive, through the application's own alias. */
    public function refuseByReply(): JsonResponse
    {
        return \Reply::json(['error' => 'locked'], 422);
    }

    private function faulted(ExportFault $fault): JsonResponse
    {
        return Response::json(['error' => $fault->name], $fault->value);
    }
}
