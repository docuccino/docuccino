<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Responses\GoneJsonResponse;
use App\Http\Responses\LockedStreamResponse;
use App\Http\Responses\MovedResponse;
use App\Http\Responses\PinnedStatusJsonResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Fluent chains on a response that set its content, its data, a cookie, a callback or its encoding, beside
 * the status, headers and media type the chain reader already reads; a download's and a redirect's own
 * setters; chains on an application's response
 * subclass, one of which overrides the status setter; and a response built into a local by the factory or
 * by `new`, returned as it is. Only ever analysed, never dispatched.
 */
class ResponseChainController extends Controller
{
    public function content(): Response
    {
        return response('queued', 202)->setContent('accepted');
    }

    public function data(): JsonResponse
    {
        return response()->json(['queued' => true], 202)->setData(['accepted' => true]);
    }

    public function dataFromRequest(Request $request): JsonResponse
    {
        return response()->json(['queued' => true], 202)->setData($request->all());
    }

    public function dataThenStatus(): JsonResponse
    {
        return response()->json(['queued' => true])->setData(['accepted' => true])->setStatusCode(202);
    }

    public function headers(): JsonResponse
    {
        return response()->json(['queued' => true], 202)->withHeaders(['Content-Type' => 'application/vnd.queue+json']);
    }

    public function header(): JsonResponse
    {
        return response()->json(['queued' => true], 202)->header('X-Queue', 'exports');
    }

    public function cookie(): JsonResponse
    {
        return response()->json(['queued' => true], 202)->cookie('queued', '1');
    }

    public function withCookie(): JsonResponse
    {
        return response()->json(['queued' => true], 202)->withCookie(cookie('queued', '1'));
    }

    public function withoutCookie(): JsonResponse
    {
        return response()->json(['queued' => true], 202)->withoutCookie('queued');
    }

    public function pretty(): JsonResponse
    {
        return response()->json(['queued' => true], 202)->setEncodingOptions(JSON_PRETTY_PRINT);
    }

    public function forceObject(): JsonResponse
    {
        return response()->json(['ids' => []], 202)->setEncodingOptions(JSON_FORCE_OBJECT);
    }

    public function callback(Request $request): JsonResponse
    {
        return response()->json(['queued' => true], 202)->setCallback($request->query('callback'));
    }

    public function noCallback(): JsonResponse
    {
        return response()->json(['queued' => true], 202)->withCallback(null);
    }

    public function unknownLink(): JsonResponse
    {
        return response()->json(['queued' => true], 202)->setNotModified();
    }

    public function cached(): JsonResponse
    {
        return response()->json(['queued' => true], 202)->setMaxAge(60)->setEtag('queued')->setPublic();
    }

    public function cacheHeaders(): JsonResponse
    {
        return response()->json(['queued' => true], 202)
            ->setCache(['private' => true])
            ->setPrivate()
            ->setImmutable()
            ->setSharedMaxAge(60)
            ->setTtl(60)
            ->setClientTtl(60)
            ->setStaleIfError(60)
            ->setStaleWhileRevalidate(60)
            ->setLastModified(now())
            ->setExpires(now()->addMinute())
            ->setDate(now())
            ->setVary('Accept')
            ->expire()
            ->setProtocolVersion('1.1')
            ->withException(new \RuntimeException('queued'));
    }

    public function download(): BinaryFileResponse
    {
        return response()->download(storage_path('exports/latest.csv'), 'latest.csv')->deleteFileAfterSend();
    }

    public function downloadHeaders(): BinaryFileResponse
    {
        return response()->download(storage_path('exports/latest.csv'))
            ->setAutoEtag()
            ->setAutoLastModified()
            ->setContentDisposition('attachment', 'latest.csv');
    }

    public function redirectFlash(): RedirectResponse
    {
        return redirect('/exports', 303)
            ->with('status', 'queued')
            ->withErrors(['export' => 'Already running.'])
            ->withInput()
            ->withCookies([cookie('export', 'queued')])
            ->withFragment('latest')
            ->withoutFragment();
    }

    public function json(): JsonResponse
    {
        return response()->json(['queued' => true], 202)->setJson('{"accepted":true}');
    }

    public function charset(): Response
    {
        return response('queued', 202)->setCharset('ISO-8859-1');
    }

    public function withoutHeader(): JsonResponse
    {
        return response()->json(['queued' => true], 202)->withoutHeader(['X-Queue', 'X-Debug']);
    }

    public function withoutContentType(): JsonResponse
    {
        return response()->json(['queued' => true], 202)->withoutHeader('Content-Type');
    }

    public function prepared(Request $request): JsonResponse
    {
        return response()->json(['queued' => true], 202)->prepare($request);
    }

    public function twoUnread(Request $request): Response
    {
        return response('queued', 202)->setContent($request->input('body'))->withHeaders($request->input('headers'));
    }

    public function twoUnreadThenStatus(Request $request): JsonResponse
    {
        return response()->json(['queued' => true])->setData($request->all())->setCallback($request->query('callback'))->setStatusCode(202);
    }

    public function subclass(): GoneJsonResponse
    {
        return (new GoneJsonResponse)->setStatusCode(404);
    }

    public function subclassHeader(): GoneJsonResponse
    {
        return (new GoneJsonResponse)->header('Content-Type', 'application/problem+json');
    }

    public function subclassStream(): LockedStreamResponse
    {
        return (new LockedStreamResponse(static function (): void {
            echo 'chunk';
        }))->setStatusCode(503);
    }

    public function subclassRedirect(): MovedResponse
    {
        return (new MovedResponse('/exports'))->setStatusCode(308);
    }

    public function overridden(): PinnedStatusJsonResponse
    {
        return (new PinnedStatusJsonResponse)->setStatusCode(404);
    }

    public function overriddenLocal(): PinnedStatusJsonResponse
    {
        $response = new PinnedStatusJsonResponse;
        $response->setStatusCode(404);

        return $response;
    }

    public function localFactory(): JsonResponse
    {
        $response = response()->json(['queued' => true], 202);

        return $response;
    }

    public function localJson(): JsonResponse
    {
        $response = new JsonResponse(['queued' => true], 202);

        return $response;
    }

    public function localPlain(): Response
    {
        $response = new Response('queued', 202);

        return $response;
    }

    public function localData(): JsonResponse
    {
        $response = new JsonResponse(['queued' => true], 202);
        $response->setData(['accepted' => true]);

        return $response;
    }

    public function localContent(): Response
    {
        $response = response('queued', 202);
        $response->setContent('accepted')->cookie('seen', '1');

        return $response;
    }
}
