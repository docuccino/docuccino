<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\ExportFault;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Plain responses the factory makes — through the `response()` helper, `response()->make()` and
 * `response()->view()` — each taking its status from a literal, a class constant, an enum case's read, a
 * named argument, a chain, or a helper it was handed to; beside one whose status is the framework's 200 and
 * one whose status nothing can read.
 */
class ResponseHelperStatusController extends Controller
{
    public function literal(): Response
    {
        return response('', 410);
    }

    public function constant(): Response
    {
        return response('archived', Response::HTTP_GONE);
    }

    public function caseRead(): Response
    {
        return response('', ExportFault::Gone->statusCode());
    }

    public function named(): Response
    {
        return response(status: 410);
    }

    public function chained(): Response
    {
        return response('')->setStatusCode(410);
    }

    public function factoryMake(): Response
    {
        return response()->make('', ExportFault::Locked->value);
    }

    public function view(): Response
    {
        return response()->view('archived', [], 410);
    }

    public function helperStatus(): Response
    {
        return $this->plain('archived', 410);
    }

    public function helperCase(): Response
    {
        return $this->faulted(ExportFault::Locked);
    }

    public function plainOk(): Response
    {
        return response('created');
    }

    public function unread(Request $request): Response
    {
        return response('', $request->integer('status'));
    }

    protected function plain(string $content, int $status): Response
    {
        return response($content, $status);
    }

    protected function faulted(ExportFault $fault): Response
    {
        return response('', $fault->statusCode());
    }
}
