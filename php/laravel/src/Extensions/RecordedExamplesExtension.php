<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Extensions;

use Docuccino\Core\Draft\OperationDraft;
use Docuccino\Core\Examples\ExampleRedaction;
use Docuccino\Core\Examples\RecordedExample;
use Docuccino\Core\Examples\RecordedExampleAudit;
use Docuccino\Core\Examples\RecordingStore;
use Docuccino\Core\Extensions\Context\RouteContext;
use Docuccino\Core\Extensions\Contracts\OperationExtension;
use Docuccino\Core\Extensions\Contracts\OperationPhase;
use Docuccino\Core\Patch\Contribution;
use Docuccino\Core\Patch\Layer;

/**
 * Publishes the response bodies a test suite recorded as the examples for their operation.
 *
 * The build reads a committed file and nothing else — no test runs here, no route is dispatched, no
 * database is opened. Execution happened in the suite, where execution already lived; what reaches the
 * document is a reviewed artifact of it — the recorder that writes them is a contract-testing observer,
 * and lives with the rest of that dev-only surface.
 *
 * A recording sits at the INTEGRATION rung of the precedence ladder: above inference, below every
 * authored source. It is evidence — the application really did answer this — so it beats a shape
 * inference merely derived; but a `#[Example]` is somebody choosing what a reader should see, and a
 * person who has chosen is never overruled by a test fixture. `setExample()` writes no provenance, so
 * the ladder is READ rather than written — see {@see authored()}.
 *
 * It is attached as the media type's own `example`, beside the schema rather than inside it, for a
 * reason worth stating: the shared-error hoist strips that member before it groups bodies, and would
 * key on one written into the schema. Recorded there, one route acquiring a recording could drop an
 * unrelated route's 404 out of its shared component and back inline — one part of an application
 * changing the emitted representation of another, which is the defect locality forbids.
 *
 * Only a status and media type the document already documents gets one. A recording is an
 * illustration, never a claim that an endpoint answers something the contract does not describe.
 */
final class RecordedExamplesExtension implements OperationExtension
{
    public function __construct(
        private readonly string $basePath,
        private readonly ExampleRedaction $redaction = new ExampleRedaction,
    ) {}

    public function phase(): OperationPhase
    {
        // After the response bodies exist, so an example is only ever attached beside a documented
        // schema; the guard, not the ordering, is what keeps an authored example on top.
        return OperationPhase::Overrides;
    }

    public function handle(OperationDraft $operation, RouteContext $context): void
    {
        $store = RecordingStore::for($context->document, $this->basePath);
        $operationId = $context->operationId;

        if ($store === null || $operationId === null) {
            return;
        }

        $path = $store->pathFor($operationId);

        if ($path === null) {
            return;
        }

        // The file shapes the emitted bytes, so it keys the fragment whether or not it exists yet —
        // recording an operation for the first time has to invalidate exactly as re-recording does.
        $context->dependencies()->addFile($path);

        $recording = $store->read($operationId);

        if ($recording === null) {
            return;
        }

        foreach ($recording->responses as $example) {
            $this->attach($operation, $example);
        }
    }

    private function attach(OperationDraft $operation, RecordedExample $example): void
    {
        if (! $operation->hasResponse($example->status)) {
            return;
        }

        $response = $operation->response($example->status);

        if (! $response->hasContent($example->mediaType)) {
            return;
        }

        // A committed body that still looks like it holds a credential is not published at all. The
        // recorder redacts on the way out, so reaching here means the file was edited by hand or the
        // heuristics have learned something since — either way the safe answer is no example, and
        // {@see RecordedExampleAudit} is what tells the author about it.
        if ($this->redaction->findings($example->body) !== []) {
            return;
        }

        // An example somebody WROTE is the one a reader should see. It lands inside the schema (that is
        // where `#[Example]` and `@example` put one), so the ladder is read there and a recording steps
        // aside for anything from the docblock rung up.
        if (self::authored($response->content($example->mediaType)->producerFor('example'))) {
            return;
        }

        // First writer wins here, which is what settles a recording against another integration-layer
        // producer that already illustrated this media type — the built-in error tiers attach the
        // literals they folded, and a value the application really returns is not better evidence than
        // a value the application's own code spells out.
        $response->setExample($example->mediaType, $example->body);
    }

    /** Whether a producer that already wrote an example outranks the integration rung. */
    private static function authored(?string $producer): bool
    {
        return $producer !== null
            && Contribution::forProducer($producer)->layer->value > Layer::Integration->value;
    }
}
