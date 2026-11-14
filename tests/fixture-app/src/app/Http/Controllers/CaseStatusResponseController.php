<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\ExportFault;
use App\Enums\TokenRefusal;
use App\Support\ProbeStatuses;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Responses whose status is read off an enum case, written inline in every place a response takes its
 * status, through a helper handed the case or its status, and with the case chosen at run time; and a status
 * written as another class's constant.
 */
class CaseStatusResponseController extends Controller
{
    public function json(): JsonResponse
    {
        return response()->json(['error' => 'expired'], TokenRefusal::Expired->status());
    }

    public function constructed(): JsonResponse
    {
        return new JsonResponse(['error' => 'rotated'], TokenRefusal::AlreadyRotated->status());
    }

    public function chain(): JsonResponse
    {
        return response()->json(['error' => 'expired'])->setStatusCode(TokenRefusal::Expired->status());
    }

    public function earlyReturn(): JsonResponse
    {
        return response()->json(['error' => 'rotated'])->setStatusCode(TokenRefusal::AlreadyRotated->httpStatus());
    }

    public function constructedValue(): Response
    {
        return new Response('', ExportFault::Gone->value);
    }

    public function noContent(): JsonResponse
    {
        return response()->noContent(ExportFault::Gone->statusCode());
    }

    public function agreeing(Request $request): JsonResponse
    {
        $reason = $request->boolean('revoked') ? TokenRefusal::Revoked : TokenRefusal::Expired;

        return response()->json(['error' => $reason->value], $reason->status());
    }

    public function disagreeing(Request $request): JsonResponse
    {
        $reason = $request->boolean('rotated') ? TokenRefusal::AlreadyRotated : TokenRefusal::Expired;

        return response()->json(['error' => $reason->value], $reason->status());
    }

    public function chainDisagreeing(Request $request): JsonResponse
    {
        $reason = $request->boolean('rotated') ? TokenRefusal::AlreadyRotated : TokenRefusal::Expired;

        return response()->json(['error' => $reason->value])->setStatusCode($reason->status());
    }

    public function helperCase(): JsonResponse
    {
        return $this->refuse(TokenRefusal::AlreadyRotated);
    }

    public function helperAgreeing(Request $request): JsonResponse
    {
        return $this->refuse($request->boolean('revoked') ? TokenRefusal::Revoked : TokenRefusal::Expired);
    }

    public function helperDisagreeing(Request $request): JsonResponse
    {
        return $this->refuse($request->boolean('rotated') ? TokenRefusal::AlreadyRotated : TokenRefusal::Expired);
    }

    public function helperStatus(): JsonResponse
    {
        return $this->fail(['error' => 'expired'], TokenRefusal::Expired->status());
    }

    public function constructedHelperStatus(): JsonResponse
    {
        return $this->send(['error' => 'rotated'], TokenRefusal::AlreadyRotated->httpStatus());
    }

    public function constant(): JsonResponse
    {
        return response()->json(['error' => 'rejected'], ProbeStatuses::REJECTED);
    }

    public function chainConstant(): JsonResponse
    {
        return response()->json(['error' => 'archived'])->setStatusCode(ProbeStatuses::ARCHIVED);
    }

    public function helperValue(): JsonResponse
    {
        return $this->fault(ExportFault::Gone);
    }

    protected function refuse(TokenRefusal $reason): JsonResponse
    {
        return response()->json(['error' => $reason->value], $reason->status());
    }

    protected function fault(ExportFault $fault): JsonResponse
    {
        return new JsonResponse(['error' => $fault->name], $fault->value);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    protected function fail(array $data, int $status): JsonResponse
    {
        return response()->json($data, $status);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    protected function send(array $data, int $status): JsonResponse
    {
        return new JsonResponse($data, $status);
    }
}
