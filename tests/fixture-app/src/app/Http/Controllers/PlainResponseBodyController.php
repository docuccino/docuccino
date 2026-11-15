<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Responses\ExpiredResponse;
use App\Http\Responses\LockedNoticeResponse;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Response as ResponseFactory;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

/**
 * Plain responses handed a string, nothing, or an array, in every spelling that makes one: constructed,
 * through the `response()` helper, the factory and the facade, Symfony's own, and a subclass handing its
 * parent the content. Laravel sends a string as it is, labelled `text/html` unless a `Content-Type` header
 * says otherwise, and an array as JSON whatever the header says.
 */
class PlainResponseBodyController extends Controller
{
    public function constructed(): Response
    {
        return new Response('The export has expired.', 410);
    }

    public function constructedEmpty(): Response
    {
        return new Response('', 410);
    }

    public function constructedArray(): Response
    {
        return new Response(['error' => 'locked'], 422);
    }

    public function constructedCsv(): Response
    {
        return new Response('id,name', 200, ['Content-Type' => 'text/csv']);
    }

    public function constructedArrayLabelled(): Response
    {
        return new Response(['error' => 'locked'], 422, ['Content-Type' => 'text/plain']);
    }

    public function helper(): Response
    {
        return response('The export has expired.', 410);
    }

    public function helperEmpty(): Response
    {
        return response('');
    }

    public function helperArray(): Response
    {
        return response(['error' => 'locked']);
    }

    public function helperPlain(): Response
    {
        return response('The export has expired.', 410, ['Content-Type' => 'text/plain']);
    }

    public function helperMessage(string $message): Response
    {
        return response($message, 503);
    }

    public function helperView(): Response
    {
        return response(view('archived'), 410);
    }

    public function facade(): Response
    {
        return ResponseFactory::make('The export has expired.', 410);
    }

    public function factory(): Response
    {
        return response()->make('The export has expired.', 410);
    }

    public function symfony(): SymfonyResponse
    {
        return new SymfonyResponse('The export has expired.', 410);
    }

    public function subclass(): ExpiredResponse
    {
        return new ExpiredResponse('The export has expired.');
    }

    public function subclassArray(): LockedNoticeResponse
    {
        return new LockedNoticeResponse;
    }
}
