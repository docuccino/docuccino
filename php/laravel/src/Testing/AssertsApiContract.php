<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Testing;

use Docuccino\Core\Contract\ContractIndex;
use Docuccino\Core\Contract\ContractMessages;
use Docuccino\Core\Contract\Examples\ExampleAudit;
use Docuccino\Core\Diff\Changeset;
use Docuccino\Core\Diff\DocumentDiffer;
use Docuccino\Core\Diff\IncomparableDocumentsException;
use Docuccino\Core\Document\UirDocument;
use Docuccino\Core\Extensions\Context\ExportTarget;
use Illuminate\Testing\TestResponse;
use JsonException;
use PHPUnit\Framework\Assert;
use Symfony\Component\HttpFoundation\Response;

/**
 * Contract assertions for a PHPUnit test case or a Pest `uses()`.
 *
 * Your suite already exercises real endpoints with real data. These hold every one of those exchanges
 * to the document Docuccino generates, and — because the document carries `x-docuccino.provenance` —
 * every failure names the producer and the `file:line` the shape came from, which is where the fix
 * usually is.
 *
 * `ApiContract::registerMacros()` puts the three exchange assertions on `TestResponse` too, if you
 * prefer `$this->getJson(…)->assertValidResponse()` to wrapping the call.
 */
trait AssertsApiContract
{
    /**
     * The request that produced this response matches what the contract documents for the operation.
     *
     * @param  TestResponse<Response>  $response
     * @return TestResponse<Response>
     */
    public function assertValidRequest(TestResponse $response): TestResponse
    {
        ApiContract::assertExchange($response, true, false);

        return $response;
    }

    /**
     * The response matches the schema the contract documents for its status and media type.
     *
     * @param  TestResponse<Response>  $response
     * @return TestResponse<Response>
     */
    public function assertValidResponse(TestResponse $response): TestResponse
    {
        ApiContract::assertExchange($response, false, true);

        return $response;
    }

    /**
     * Both halves at once.
     *
     * @param  TestResponse<Response>  $response
     * @return TestResponse<Response>
     */
    public function assertValidExchange(TestResponse $response): TestResponse
    {
        ApiContract::assertExchange($response, true, true);

        return $response;
    }

    /**
     * Every example the document publishes satisfies the schema beside it.
     *
     * Inference cannot be wrong about a shape it derived; a hand-written `#[Example]` can say anything
     * at all, and it is the part of the document a reader copies. This is the one check that holds it
     * to the same contract a client is held to.
     */
    public function assertValidExamples(): void
    {
        $report = (new ExampleAudit(ApiContract::index()))->run();

        Assert::assertTrue($report->ok(), ContractMessages::examples($report));
    }

    /**
     * The document this code generates makes no breaking change to the committed artifact.
     *
     * It runs `DocumentDiffer` — the same semantic, id-based diff `docuccino:diff` runs — so the
     * assertion and the command can never disagree about what breaking means. Pass a git ref to compare
     * against a branch or tag rather than the working tree, exactly as `--against` does.
     */
    public function assertNoBreakingChanges(?string $against = null): void
    {
        $build = ApiContract::build();
        $target = ArtifactLocator::preferred($build->config());

        if ($against === null) {
            $json = $build->committed($target->path);
            $problem = sprintf('%s is not there', $build->absolute($target->path));
        } else {
            [$json, $stderr] = $build->committedAtRef($against, $target->path);
            $problem = sprintf('git show %s:%s failed — %s', $against, $target->path, $stderr);
        }

        if ($json === null) {
            Assert::fail(sprintf(
                "There is no committed contract to compare against: %s.\n".
                'Export one and commit it: php artisan docuccino:export',
                $problem,
            ));
        }

        $old = self::decodeDocument($json, $build->absolute($target->path));
        $new = $build->fresh();

        try {
            $changeset = (new DocumentDiffer)->diff(UirDocument::fromArray($old), $new);
        } catch (IncomparableDocumentsException $exception) {
            Assert::fail($exception->getMessage());
        }

        Assert::assertFalse($changeset->isBreaking(), ContractMessages::breaking(
            $changeset,
            ContractIndex::fromArray($new->toArray()),
            ContractIndex::fromArray($old),
            'If the change is deliberate, re-export the artifact and raise the document version: php artisan docuccino:export',
        ));
    }

    /**
     * The committed artifacts are what this code produces right now.
     *
     * A BYTE comparison, which is only a fair test because Docuccino's output is deterministic:
     * identical code produces identical bytes, so a difference always has a cause. Emit options that do
     * not change the contract — how much provenance detail is kept, whether ids are re-emitted — are
     * compared across every form the exporter can write, so a document exported at a different
     * `--provenance` level does not read as stale.
     */
    public function assertDocumentUpToDate(): void
    {
        $build = ApiContract::build();
        $targets = $build->config()->exportTargets();

        foreach ($targets as $target) {
            $committed = $build->committed($target->path);

            if ($committed === null) {
                Assert::fail(sprintf(
                    "%s has never been written.\nExport it and commit it: php artisan docuccino:export",
                    $build->absolute($target->path),
                ));
            }

            if (in_array($committed, $build->emissions($target), true)) {
                continue;
            }

            Assert::fail(ContractMessages::stale(
                $target->path,
                ...$this->staleDetail($build, $target, $committed),
                hint: 'Regenerate it: php artisan docuccino:export',
            ));
        }

        // A document with no target would otherwise pass having compared nothing.
        Assert::assertThat($targets, Assert::logicalNot(Assert::isEmpty()));
    }

    /** Every documented operation was exercised by this run. */
    public function assertEveryOperationExercised(): void
    {
        $this->assertContractCoverage(100.0);
    }

    /**
     * At least $minimum percent of documented operations were exercised.
     *
     * Set it to what you measure today and ratchet it up, the way a line-coverage floor works: a floor
     * you cannot meet is one people delete. Put the assertion in a test of its own, last — it can only
     * see what has run by the time it runs.
     */
    public function assertContractCoverage(float $minimum): void
    {
        if (ParallelRun::active()) {
            $worker = ParallelRun::worker();

            Assert::fail(sprintf(
                "Contract coverage cannot be measured from inside a parallel test run%s.\n".
                "Each worker exercises its own share of the suite and none of them can know when the\n".
                "others have finished, so any answer taken here would name operations the suite covered\n".
                "perfectly well. Run the coverage check in a single-process job (drop --parallel), or\n".
                'read ApiContract::report() after the run instead.',
                $worker === null ? '' : ' (worker '.$worker.')',
            ));
        }

        $report = ApiContract::report();

        Assert::assertTrue($report->meets($minimum), $report->render($minimum));
    }

    /**
     * The changeset and both indexes behind a stale artifact, or nulls when the committed file is not a
     * document that can be compared semantically (a Postman collection, say).
     *
     * @return array{0: Changeset|null, 1: ContractIndex|null, 2: ContractIndex|null}
     */
    private function staleDetail(ContractBuild $build, ExportTarget $target, string $committed): array
    {
        if (! in_array($target->format, ['uir', 'openapi-3.2', 'openapi-3.1', 'openapi-3.0'], true) || $target->yaml()) {
            return [null, null, null];
        }

        try {
            $decoded = json_decode($committed, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return [null, null, null];
        }

        if (! is_array($decoded)) {
            return [null, null, null];
        }

        /** @var array<string, mixed> $decoded */
        $new = $build->fresh();

        try {
            $changeset = (new DocumentDiffer)->diff(UirDocument::fromArray($decoded), $new);
        } catch (IncomparableDocumentsException) {
            return [null, null, null];
        }

        return [$changeset, ContractIndex::fromArray($new->toArray()), ContractIndex::fromArray($decoded)];
    }

    /**
     * @return array<string, mixed>
     */
    private static function decodeDocument(string $json, string $path): array
    {
        try {
            $decoded = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw UnreadableContract::notJson($path, $exception->getMessage());
        }

        if (! is_array($decoded)) {
            throw UnreadableContract::notJson($path, 'its JSON is not an object');
        }

        /** @var array<string, mixed> */
        return $decoded;
    }
}
