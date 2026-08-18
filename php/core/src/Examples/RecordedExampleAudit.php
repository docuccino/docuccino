<?php

declare(strict_types=1);

namespace Docuccino\Core\Examples;

use Docuccino\Core\Diagnostics\Diagnostic;
use Docuccino\Core\Diagnostics\Severity;
use Docuccino\Core\Extensions\Context\DocumentContext;
use Docuccino\Core\Extensions\Contracts\DocumentTransformer;
use Docuccino\Core\Extensions\Document\UirDocumentDraft;
use Docuccino\Core\Lint\LintOperation;

/**
 * Says what is wrong with the committed recordings, once per document.
 *
 * Every recording diagnostic is raised here rather than beside the extension that publishes one, for
 * two reasons. A transformer sees the whole document, which is the only place a recording nobody
 * claimed can be told from one that is simply for another operation; and a transformer runs on every
 * build, warm or cold, so what a warm build reports is what a cold one reports without any of it
 * having to ride a cached fragment.
 *
 * Diagnostics only — it never touches the document. The publishing half applies the same safety rule
 * silently, so nothing here is a report about something that already shipped.
 *
 * @internal
 */
final readonly class RecordedExampleAudit implements DocumentTransformer
{
    public function __construct(
        private string $basePath,
        private ExampleRedaction $redaction = new ExampleRedaction,
    ) {}

    public function transform(UirDocumentDraft $document, DocumentContext $context): void
    {
        $store = RecordingStore::for($context->config, $this->basePath);

        if ($store === null) {
            return;
        }

        $files = $store->fileNames();

        if ($files === []) {
            $context->report(new Diagnostic(
                severity: Severity::Info,
                code: 'examples.recordings-empty',
                message: sprintf('No response recordings were found in %s, so the document publishes none.', $store->directory),
                help: 'Record some by registering Docuccino\\Laravel\\Testing\\ApiContract::record() in your test bootstrap and running the suite, or drop examples.recordings from the document config.',
            ));

            return;
        }

        $documented = self::operationIds($document->toArray());

        foreach ($files as $file) {
            $this->check($store, $file, $documented, $context);
        }
    }

    /**
     * @param  array<string, true>  $documented
     */
    private function check(RecordingStore $store, string $file, array $documented, DocumentContext $context): void
    {
        $path = $store->directory.'/'.$file;
        $recording = RecordingStore::at($path);

        if ($recording === null) {
            $context->report(new Diagnostic(
                severity: Severity::Warning,
                code: 'examples.recording-unreadable',
                message: sprintf('%s is not a response recording Docuccino can read, so nothing from it is published.', $file),
                help: 'Re-record it by running your suite with the recorder registered, or delete the file.',
            ));

            return;
        }

        $operationId = RecordingStore::operationIdFor($file);

        if ($operationId !== $recording->operationId) {
            $context->report(new Diagnostic(
                severity: Severity::Warning,
                code: 'examples.recording-unreadable',
                message: sprintf('%s records operation %s, which is not the operation its filename names.', $file, $recording->operationId),
                help: 'Delete the file and re-record it — a recording is filed under the id it holds.',
            ));

            return;
        }

        if (! isset($documented[$operationId])) {
            $context->report(new Diagnostic(
                severity: Severity::Warning,
                code: 'examples.recording-orphaned',
                message: sprintf(
                    'The recording in %s is for an operation this document no longer has (%s%s).',
                    $file,
                    $operationId,
                    $recording->endpoint === '' ? '' : ', recorded from '.$recording->endpoint,
                ),
                help: 'The route was renamed, moved or removed. Delete the file, then re-record the operation that replaced it.',
            ));

            return;
        }

        foreach ($recording->responses as $response) {
            $findings = $this->redaction->findings($response->body);

            if ($findings === []) {
                continue;
            }

            $context->report(new Diagnostic(
                severity: Severity::Warning,
                code: 'examples.recording-unsafe',
                message: sprintf(
                    'The %s %s recording in %s still holds what looks like a credential at %s; it is not published.',
                    $response->status,
                    $response->mediaType,
                    $file,
                    implode(', ', $findings),
                ),
                help: 'Re-record it — the recorder replaces credentials on the way out. If the value is genuinely public, list its pointer or its property name under lint.leakage.allow.',
            ));
        }
    }

    /**
     * Every operation id the document publishes.
     *
     * @param  array<string, mixed>  $document
     * @return array<string, true>
     */
    private static function operationIds(array $document): array
    {
        $ids = [];

        foreach (LintOperation::all($document) as $operation) {
            $extension = $operation->operation['x-docuccino'] ?? null;
            $id = is_array($extension) ? ($extension['id'] ?? null) : null;

            if (is_string($id) && $id !== '') {
                $ids[$id] = true;
            }
        }

        return $ids;
    }
}
