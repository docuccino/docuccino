<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\ExportFault;
use Illuminate\Http\Request;
use Illuminate\Http\StreamedEvent;
use Illuminate\Support\Facades\Response;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;
use Symfony\Component\HttpFoundation\StreamedJsonResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Streamed and file responses sent with a status of their own: made by the factory, through the `Response`
 * facade and with `new`, the status a literal, a class constant, an enum case's read, a named argument, a
 * choice, a chain, or a helper's parameter; beside the calls that take no status at all, one sent with the
 * framework's 200 and one whose status nothing can read. Only ever analysed, never dispatched.
 */
class StreamStatusController extends Controller
{
    public function stream(): StreamedResponse
    {
        return response()->stream(static function (): void {
            echo "id,total\n";
        }, 202, ['Content-Type' => 'text/csv']);
    }

    public function streamJson(): StreamedJsonResponse
    {
        return response()->streamJson(['rows' => [1, 2, 3]], 206);
    }

    public function constant(): StreamedResponse
    {
        return response()->stream(static function (): void {
            echo 'chunk';
        }, SymfonyResponse::HTTP_ACCEPTED);
    }

    public function caseRead(): StreamedResponse
    {
        return response()->stream(static function (): void {
            echo 'chunk';
        }, ExportFault::Gone->value);
    }

    public function named(): StreamedJsonResponse
    {
        return response()->streamJson(status: 206, data: ['rows' => []]);
    }

    public function choice(bool $partial): StreamedJsonResponse
    {
        return response()->streamJson(['rows' => []], $partial ? 206 : 200);
    }

    public function chained(): StreamedResponse
    {
        return response()->stream(static function (): void {
            echo 'chunk';
        })->setStatusCode(202);
    }

    public function local(): StreamedResponse
    {
        $response = response()->stream(static function (): void {
            echo 'chunk';
        }, 202);
        $response->headers->set('X-Export', 'pending');

        return $response;
    }

    public function facade(): StreamedJsonResponse
    {
        return Response::streamJson(['rows' => []], 206);
    }

    public function constructed(): StreamedResponse
    {
        return new StreamedResponse(static function (): void {
            echo 'chunk';
        }, 202);
    }

    public function constructedJson(): StreamedJsonResponse
    {
        return new StreamedJsonResponse(['rows' => []], 206);
    }

    public function constructedFile(): BinaryFileResponse
    {
        return new BinaryFileResponse(storage_path('exports/report.pdf'), 206);
    }

    public function helperStatus(): StreamedResponse
    {
        return $this->streamed(202);
    }

    public function helperDefault(): StreamedResponse
    {
        return $this->streamed();
    }

    public function helperCase(): StreamedResponse
    {
        return $this->faulted(ExportFault::Locked);
    }

    public function streamOk(): StreamedResponse
    {
        return response()->stream(static function (): void {
            echo "id,total\n";
        }, 200, ['Content-Type' => 'text/csv']);
    }

    public function streamDownload(): StreamedResponse
    {
        return response()->streamDownload(static function (): void {
            echo 'chunk';
        }, 'ledger.csv');
    }

    public function events(): StreamedResponse
    {
        return response()->eventStream(function () {
            yield new StreamedEvent(event: 'update', data: 'ok');
        });
    }

    public function unread(Request $request): StreamedResponse
    {
        return response()->stream(static function (): void {
            echo 'chunk';
        }, $request->integer('status'));
    }

    protected function streamed(int $status = 200): StreamedResponse
    {
        return response()->stream(static function (): void {
            echo 'chunk';
        }, $status);
    }

    protected function faulted(ExportFault $fault): StreamedResponse
    {
        return response()->stream(static function (): void {
            echo 'chunk';
        }, $fault->statusCode());
    }
}
