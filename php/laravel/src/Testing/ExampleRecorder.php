<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Testing;

use Docuccino\Core\Contract\MediaType;
use Docuccino\Core\Examples\ExampleRecording;
use Docuccino\Core\Examples\ExampleRedaction;
use Docuccino\Core\Examples\RecordedBody;
use Docuccino\Core\Examples\RecordedExample;
use Docuccino\Core\Examples\RecordingStore;
use Docuccino\Laravel\Support\Paths;
use Docuccino\Laravel\Testing\Contracts\ContractObserver;
use JsonException;

/**
 * Turns the responses your suite already produces into the examples the document publishes.
 *
 * It is a {@see ContractObserver}, which is the whole point: the assertion path has already matched the
 * exchange to its operation and already checked the body against the documented schema, so a recorder
 * hung off that seam needs no matching logic of its own and can only ever record a response that
 * agreed with the contract at the moment it was recorded.
 *
 * What it writes is a committed file per operation, named after the operation's stable id. The
 * document build reads those files and nothing else, so "Docuccino never executes your application
 * code" stays exactly as true as it was: the execution is your test suite's, which is where it already
 * lived.
 *
 * Three rules do the curating, and each of them is about the recording being a function of the
 * responses rather than of the run:
 *
 * - only an exchange whose RESPONSE half was checked and passed is recorded, so a body that
 *   contradicts its own schema can never become the illustration of it;
 * - among the many responses a suite produces for one status, the published one is the best
 *   ({@see RecordedExample::outranks()}) and never the first, so reordering tests moves nothing;
 * - a committed body is left alone while its SHAPE is unchanged, so a timestamp or an autoincrement
 *   key in a payload cannot make the artifact churn on every re-record.
 *
 * Credentials are replaced on the way out ({@see ExampleRedaction}), before anything reaches disk.
 */
final class ExampleRecorder implements ContractObserver
{
    /** @var array<string, array<string, RecordedExample>> operation id → response key → best so far */
    private array $best = [];

    /** @var array<string, string> operation id → `GET /api/invoices/{invoice}`, prose for the reviewer */
    private array $endpoints = [];

    /** @var array<string, ExampleRecording> operation id → what was committed before this run */
    private array $committed = [];

    private ?RecordingStore $store = null;

    public function __construct(
        private readonly ?string $directory = null,
        private ?ExampleRedaction $redaction = null,
    ) {
        // The same answer the coverage assertions give, for the same reason. Coverage cannot be MERGED
        // across workers; a recording cannot be CHOSEN across them — each worker would pick the best of
        // its own share and the last writer would win, which is a published example decided by
        // scheduling. Refusing is the only answer that keeps the file a function of the suite.
        if (ParallelRun::active()) {
            throw UnrecordableRun::parallel(ParallelRun::worker());
        }
    }

    public function observed(ObservedExchange $exchange): void
    {
        $operationId = $exchange->operationId();

        if ($operationId === null) {
            return;
        }

        $example = $this->exampleFor($exchange);

        if ($example === null) {
            return;
        }

        $key = $example->key();
        $incumbent = $this->best[$operationId][$key] ?? null;

        if ($incumbent !== null && ! $example->outranks($incumbent)) {
            return;
        }

        $this->best[$operationId][$key] = $example;
        $this->endpoints[$operationId] = $exchange->method().' '.$exchange->pathTemplate();

        $this->write($operationId);
    }

    /**
     * The redaction rules, resolved on first use from the container so they carry the application's own
     * `lint.leakage` heuristics — a test bootstrap constructs the recorder before there is a container
     * to ask.
     */
    private function redaction(): ExampleRedaction
    {
        return $this->redaction ??= app(ExampleRedaction::class);
    }

    /** Where recordings are being written, resolved on first use — a test bootstrap runs early. */
    public function store(): RecordingStore
    {
        if ($this->store !== null) {
            return $this->store;
        }

        $document = ApiContract::documentKey();
        $store = $this->directory !== null
            ? new RecordingStore(Paths::absolute($this->directory, base_path()))
            : RecordingStore::for(ApiContract::build()->config(), base_path());

        if ($store === null) {
            throw UnrecordableRun::unconfigured($document);
        }

        return $this->store = $store;
    }

    /**
     * The example this exchange offers, or null when it offers none.
     *
     * A response that was not checked is not evidence of anything — the suite asserted the request half
     * and said nothing about the body — and neither is one that failed. A body JSON Schema could not
     * check (a CSV download, an image) is not an example a document can publish either.
     */
    private function exampleFor(ObservedExchange $exchange): ?RecordedExample
    {
        $outcome = $exchange->result->response;

        if ($outcome === null || ! $outcome->ok()) {
            return null;
        }

        $mediaType = MediaType::base($exchange->exchange->responseContentType);

        if ($mediaType === null || ! MediaType::isJson($mediaType)) {
            return null;
        }

        $body = $exchange->body();

        if ($body === '') {
            return null;
        }

        try {
            $decoded = RecordedBody::decode($body);
        } catch (JsonException) {
            return null;
        }

        [$redacted] = $this->redaction()->apply($decoded);

        return RecordedExample::of((string) $exchange->status(), $mediaType, $redacted);
    }

    /**
     * Rewrite this operation's file from what was committed plus this run's winners — always from the
     * ORIGINAL committed recording, never from what an earlier write in this run left behind, so the
     * result is the same whatever order the suite ran in.
     */
    private function write(string $operationId): void
    {
        $store = $this->store();
        $original = $this->committed[$operationId] ??= $store->read($operationId)
            ?? ExampleRecording::of($operationId, '');

        $recording = $original->labelled($this->endpoints[$operationId] ?? '');

        foreach ($this->best[$operationId] ?? [] as $example) {
            $recording = $recording->with($example);
        }

        $store->put($recording);
    }
}
