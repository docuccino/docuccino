<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Extensions;

use Docuccino\Core\Draft\OperationDraft;
use Docuccino\Core\Draft\ResponseDraft;
use Docuccino\Core\Examples\ExampleRecording;
use Docuccino\Core\Examples\ExampleRedaction;
use Docuccino\Core\Examples\RecordedExample;
use Docuccino\Core\Examples\RecordedExampleAudit;
use Docuccino\Core\Examples\RecordingStore;
use Docuccino\Core\Extensions\BuiltIn\SharedErrorResponses;
use Docuccino\Core\Extensions\Context\RepresentationPolicy;
use Docuccino\Core\Extensions\Context\RouteContext;
use Docuccino\Core\Extensions\Contracts\OperationExtension;
use Docuccino\Core\Extensions\Contracts\OperationPhase;

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
 * person who has chosen is never overruled by a test fixture. That rung is settled by the draft rather
 * than here: a recording fills an ILLUSTRATION bag, `#[Example]` fills a DECLARATION bag, and
 * {@see ResponseDraft::freeze()} publishes a declaration over an illustration whichever ran first.
 *
 * What a recording gets is the slot its NAME asks for, and the six answers are all one rule — a
 * declaration wins, and a name somebody chose can sit beside another:
 *
 * | The author wrote | An unnamed recording | Named recordings |
 * | --- | --- | --- |
 * | nothing | the media type's `example` | an `examples` map of the recorded names |
 * | a singular `example` | the author's, alone | the author's, alone |
 * | an `examples` map | the author's map, alone | the author's map plus the recorded names, the author winning any name they both spell |
 *
 * A singular declaration is where a recording has nowhere to go: OpenAPI carries `example` or
 * `examples` and never both, so joining one would mean filing the author's own example under a key
 * they never chose. A map is different — a name a test passed at the call site is a name somebody
 * chose, which is the whole reason naming a recording is worth having.
 *
 * The example goes on the media type, beside the schema rather than inside it, for a reason worth
 * stating: the shared-error hoist strips that member before it groups bodies, and would key on one
 * written into the schema. Recorded there, one route acquiring a recording could drop an unrelated
 * route's 404 out of its shared component and back inline — one part of an application changing the
 * emitted representation of another, which is the defect locality forbids. An `examples` MAP is not
 * stripped, so on a status that hoist groups ({@see SharedErrorResponses::shares()}) named recordings
 * publish the best of their bodies as the singular `example` instead, and
 * {@see RecordedExampleAudit} tells the author their names went nowhere.
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
        // schema. Nothing here depends on running before or after the attribute layer: which of the two
        // bags publishes is the draft's answer, not the pipeline's.
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

        $hoists = RepresentationPolicy::fromConfig($context->document->representation)->errorComponents;

        foreach (self::slots($recording) as $examples) {
            $this->attach($operation, $examples, $hoists);
        }
    }

    /**
     * The recording's examples grouped by the media type they illustrate, in the file's own key order.
     *
     * @return list<non-empty-list<RecordedExample>>
     */
    private static function slots(ExampleRecording $recording): array
    {
        $slots = [];

        foreach ($recording->responses as $example) {
            $slots[$example->slot()][] = $example;
        }

        return array_values($slots);
    }

    /**
     * @param  non-empty-list<RecordedExample>  $examples  every example recorded for one media type,
     *                                                     named or unnamed but never a mix of the two
     */
    private function attach(OperationDraft $operation, array $examples, bool $hoists): void
    {
        $first = $examples[0];

        if (! $operation->hasResponse($first->status)) {
            return;
        }

        $response = $operation->response($first->status);

        if (! $response->hasContent($first->mediaType)) {
            return;
        }

        // A committed body that still looks like it holds a credential is not published at all. The
        // recorder redacts on the way out, so reaching here means the file was edited by hand or the
        // heuristics have learned something since — either way the safe answer is no example, and
        // {@see RecordedExampleAudit} is what tells the author about it.
        $safe = array_values(array_filter(
            $examples,
            fn (RecordedExample $example): bool => $this->redaction->findings($example->body) === [],
        ));

        if ($safe === []) {
            return;
        }

        if (! $first->isNamed() || ($hoists && SharedErrorResponses::shares($first->status))) {
            // First writer wins here, which is what settles a recording against another integration-layer
            // producer that already illustrated this media type — the built-in error tiers attach the
            // literals they folded, and a value the application really returns is not better evidence than
            // a value the application's own code spells out.
            $response->setExample($first->mediaType, self::best($safe)->body);

            return;
        }

        $named = [];
        foreach ($safe as $example) {
            $named[$example->name] = ['value' => $example->body];
        }

        $response->illustrateExamples($first->mediaType, $named);
    }

    /**
     * @param  non-empty-list<RecordedExample>  $examples
     */
    private static function best(array $examples): RecordedExample
    {
        $best = $examples[0];

        foreach ($examples as $example) {
            if ($example->outranks($best)) {
                $best = $example;
            }
        }

        return $best;
    }
}
