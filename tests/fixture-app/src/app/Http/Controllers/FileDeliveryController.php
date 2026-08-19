<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use Illuminate\Http\Response;
use Illuminate\Http\StreamedEvent;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The rest of the framework response family a controller declares by hand: the two Symfony responses
 * Laravel's own download/stream helpers hand back, and the plain `Illuminate\Http\Response` behind
 * `response()`. None of them carries a JSON body, and the CLASS is the same whichever helper built it —
 * which is the point: only the call says whether it is a download, and under what media type.
 * Only ever analysed, never dispatched.
 */
final class FileDeliveryController
{
    /** The stock file-download signature — Laravel returns Symfony's BinaryFileResponse. */
    public function download(): BinaryFileResponse
    {
        return response()->download(storage_path('app/exports/invoices.pdf'));
    }

    /** The same class served for display: `file()` passes no disposition, so it sets no header at all. */
    public function preview(): BinaryFileResponse
    {
        return response()->file(storage_path('app/exports/invoices.pdf'));
    }

    /** A chunked CSV export — Laravel returns Symfony's StreamedResponse. */
    public function export(): StreamedResponse
    {
        return response()->stream(static function (): void {
            echo "id,total\n";
        }, 200, ['Content-Type' => 'text/csv']);
    }

    /** A streamed download that names the file but never the media type Symfony will send. */
    public function ledger(): StreamedResponse
    {
        return response()->streamDownload(static function (): void {
            echo "id,total\n";
        }, 'ledger.csv');
    }

    /** Server-sent events: Laravel merges its own `text/event-stream` over anything the caller passes. */
    public function events(): StreamedResponse
    {
        return response()->eventStream(function () {
            yield new StreamedEvent(event: 'update', data: 'ok');
        });
    }

    /** A file off a disk — that one is a StreamedResponse, not a BinaryFileResponse. */
    public function invoice(): StreamedResponse
    {
        return Storage::download('exports/invoices.pdf', 'invoice-2026.pdf');
    }

    /** A plain text body — the base `Illuminate\Http\Response`. */
    public function health(): Response
    {
        return response('ok');
    }
}
