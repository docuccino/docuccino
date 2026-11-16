<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\ExportFault;
use App\Http\Responses\AcceptedResponse;
use App\Http\Responses\BranchingResponse;
use App\Http\Responses\ConflictProblemResponse;
use App\Http\Responses\ExpiredResponse;
use App\Http\Responses\FaultStreamResponse;
use App\Http\Responses\GoneJsonResponse;
use App\Http\Responses\GoneResponse;
use App\Http\Responses\GoneResult;
use App\Http\Responses\LockedStreamResponse;
use App\Http\Responses\MovedResponse;
use App\Http\Responses\PartialFileResponse;
use App\Http\Responses\PendingJsonResponse;
use App\Http\Responses\QuotaResponse;
use App\Http\Responses\RetryLaterResponse;
use App\Http\Responses\UnavailableResponse;

/**
 * Responses the application subclasses so that the class names its status: each constructor hands the
 * framework's constructor a literal, a constant, an enum case's read, its own parameter, or sets the status
 * itself, one or two classes below the framework. Only ever analysed, never dispatched.
 */
class ResponseSubclassController extends Controller
{
    public function gone(): GoneResponse
    {
        return new GoneResponse;
    }

    public function goneMade(): GoneResponse
    {
        return GoneResponse::make();
    }

    public function retryLater(): RetryLaterResponse
    {
        return new RetryLaterResponse(['retry' => true]);
    }

    public function retryPassed(): RetryLaterResponse
    {
        return new RetryLaterResponse(['retry' => true], 429);
    }

    public function moved(): MovedResponse
    {
        return new MovedResponse('/elsewhere');
    }

    public function locked(): LockedStreamResponse
    {
        return new LockedStreamResponse(static function (): void {
            echo 'chunk';
        });
    }

    public function faulted(): FaultStreamResponse
    {
        return new FaultStreamResponse(static function (): void {
            echo 'chunk';
        }, ExportFault::Gone);
    }

    public function partial(): PartialFileResponse
    {
        return new PartialFileResponse(storage_path('report.pdf'));
    }

    public function accepted(): AcceptedResponse
    {
        return new AcceptedResponse;
    }

    public function expired(): ExpiredResponse
    {
        return new ExpiredResponse;
    }

    public function conflict(): ConflictProblemResponse
    {
        return new ConflictProblemResponse('The slug is taken.');
    }

    public function goneJson(): GoneJsonResponse
    {
        return new GoneJsonResponse;
    }

    public function goneResult(): GoneResult
    {
        return new GoneResult;
    }

    public function retryNamed(): RetryLaterResponse
    {
        return new RetryLaterResponse(status: 429, data: ['retry' => true]);
    }

    public function retryHelper(): RetryLaterResponse
    {
        return $this->retry(429);
    }

    public function retryHelperDefault(): RetryLaterResponse
    {
        return $this->retry();
    }

    public function faultChosen(bool $locked): FaultStreamResponse
    {
        return new FaultStreamResponse(static function (): void {
            echo 'chunk';
        }, $locked ? ExportFault::Locked : ExportFault::Gone);
    }

    public function unavailable(): UnavailableResponse
    {
        return new UnavailableResponse;
    }

    public function quota(): QuotaResponse
    {
        return QuotaResponse::exceeded();
    }

    public function branching(): BranchingResponse
    {
        return new BranchingResponse;
    }

    public function branchingJson(): PendingJsonResponse
    {
        return new PendingJsonResponse(['queued' => true]);
    }

    private function retry(int $status = 503): RetryLaterResponse
    {
        return new RetryLaterResponse(['retry' => true], $status);
    }
}
