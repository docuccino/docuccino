<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use Illuminate\Http\Response;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The rest of the framework response family a controller declares by hand: the two Symfony responses
 * Laravel's own `download()`/`stream()` helpers hand back, and the plain `Illuminate\Http\Response`
 * behind `response()`. None of them carries a JSON body. Only ever analysed, never dispatched.
 */
final class FileDeliveryController
{
    /** The stock file-download signature — Laravel returns Symfony's BinaryFileResponse. */
    public function download(): BinaryFileResponse
    {
        return response()->download(storage_path('app/exports/invoices.pdf'));
    }

    /** A chunked CSV export — Laravel returns Symfony's StreamedResponse. */
    public function export(): StreamedResponse
    {
        return response()->stream(static function (): void {
            echo "id,total\n";
        }, 200, ['Content-Type' => 'text/csv']);
    }

    /** A plain text body — the base `Illuminate\Http\Response`. */
    public function health(): Response
    {
        return response('ok');
    }
}
