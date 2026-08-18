<?php

declare(strict_types=1);

use Docuccino\Core\Inference\TypeEngine;
use Docuccino\Laravel\Tests\Support\WorkbenchEngine;
use Docuccino\Laravel\Watch\WatchSignal;
use Illuminate\Support\Facades\Gate;

/**
 * The viewer's live-reload channel. Two rules do the work: it goes through exactly the gate the rest
 * of the viewer goes through — no second policy — and it does not exist at all unless a
 * `docuccino:watch` session has published a signal, so nowhere without a watcher running answers it
 * and no production page carries a subscriber.
 */
beforeEach(function (): void {
    app()->instance(TypeEngine::class, WorkbenchEngine::make());

    // A signal of this run's own: the shipped path is one file under the shared testbench app, and
    // Paratest would have one worker's session switching another worker's endpoint on.
    $this->signal = new WatchSignal(sys_get_temp_dir().'/docuccino-signal-'.uniqid('', true));
    app()->instance(WatchSignal::class, $this->signal);

    $this->token = str_repeat('ab', 32);
});

afterEach(function (): void {
    $this->signal->clear();
});

it('does not exist while no watch session is running', function (): void {
    config()->set('docuccino.documents.default.viewer.gate', 'viewApiDocs');
    Gate::before(static fn ($user = null): bool => true);

    // Authorized, and still a 404: nothing published a signal.
    $this->get('/docs/api/reload')->assertNotFound();
});

it('answers a running session with one reload event naming the built documentation', function (): void {
    config()->set('docuccino.documents.default.viewer.gate', 'viewApiDocs');
    Gate::before(static fn ($user = null): bool => true);
    $this->signal->publish($this->token);

    $response = $this->get('/docs/api/reload');

    $response->assertOk()
        ->assertHeader('Content-Type', 'text/event-stream; charset=UTF-8')
        ->assertHeader('Cache-Control', 'no-cache, private');

    expect($response->getContent())->toBe("retry: 2000\nevent: reload\ndata: {$this->token}\n\n");
});

it('goes through the same gate as the rest of the viewer', function (): void {
    $this->signal->publish($this->token);

    // The default policy — no gate configured, not the local environment — denies the reload channel
    // exactly as it denies the page and the spec.
    $this->get('/docs/api/reload')->assertForbidden();

    config()->set('docuccino.documents.default.viewer.gate', 'viewApiDocs');
    Gate::before(static fn ($user = null): bool => false);
    $this->get('/docs/api/reload')->assertForbidden();
});

it('404s a reload channel for a document that is not configured', function (): void {
    config()->set('docuccino.documents.default.viewer.gate', 'viewApiDocs');
    Gate::before(static fn ($user = null): bool => true);
    $this->signal->publish($this->token);

    config()->set('docuccino.documents', []);

    $this->get('/docs/api/reload')->assertNotFound();
});

it('subscribes the viewer page only while a watch session is running', function (): void {
    config()->set('docuccino.documents.default.viewer.gate', 'viewApiDocs');
    Gate::before(static fn ($user = null): bool => true);

    // Nothing polling on a page served without a watcher…
    $this->get('/docs/api')->assertOk()->assertDontSee('EventSource', false);

    $this->signal->publish($this->token);

    // …and a subscriber pointed at this document's own reload route when there is one.
    $this->get('/docs/api')
        ->assertOk()
        ->assertSee('new EventSource(url)', false)
        ->assertSee('"'.url('/docs/api/reload').'"', false)
        // Spliced INSIDE the document rather than tacked on after it.
        ->assertSee('</script></body>', false);
});
