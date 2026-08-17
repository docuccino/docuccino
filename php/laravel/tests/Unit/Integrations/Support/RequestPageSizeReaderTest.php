<?php

declare(strict_types=1);

use Docuccino\Core\Inference\DType\ClassT;
use Docuccino\Core\Inference\DType\ScalarT;
use Docuccino\Laravel\Integrations\Support\RequestPageSizeReader;
use Docuccino\Laravel\Tests\Support\StubTraceScope;
use PhpParser\Node;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitorAbstract;
use PhpParser\ParserFactory;
use Workbench\App\Support\ListPageSize;

/**
 * The page-size recovery's mechanics, in-process over real php-parser nodes and REAL reflection: the
 * source-range correlation that ties a read inside a callee back to the paginator argument that named it is
 * proven against {@see ListPageSize}'s actual lines, not a hand-built range. Only the type scope is stubbed;
 * the real engine proves the same recovery end-to-end in the `fixture` group.
 *
 * Every case pins the same contract from one side or the other: a key is documented when the size argument
 * was followed to a request read, and nothing is documented otherwise.
 */

/** The terminals a visitor would hand the reader — the reader itself decides which it knows. */
const PAGE_SIZE_TERMINALS = ['paginate', 'simplePaginate', 'cursorPaginate', 'paginateList'];

/**
 * Walks one snippet (or one real file's source) through the reader the way a visitor does: `observe()` on
 * every node, `terminal()` on every paginating call.
 */
function walkPageSize(
    RequestPageSizeReader $reader,
    string $code,
    string $file,
    string $receiver = 'Illuminate\\Http\\Request',
): void {
    $ast = (new ParserFactory)->createForNewestSupportedVersion()->parse($code) ?? [];
    $scope = new StubTraceScope(
        $receiver === '' ? new ScalarT('int') : new ClassT($receiver),
        file: $file,
    );

    $traverser = new NodeTraverser(new class($reader, $scope) extends NodeVisitorAbstract
    {
        public function __construct(
            private readonly RequestPageSizeReader $reader,
            private readonly StubTraceScope $scope,
        ) {}

        public function enterNode(Node $node): ?int
        {
            $this->reader->observe($node, $this->scope);

            $called = ($node instanceof Node\Expr\MethodCall || $node instanceof Node\Expr\StaticCall)
                && $node->name instanceof Node\Identifier
                    ? $node->name->toString()
                    : null;

            if ($called !== null && in_array($called, PAGE_SIZE_TERMINALS, true)) {
                $this->reader->terminal($node, $called, $this->scope);
            }

            return null;
        }
    });
    $traverser->traverse($ast);
}

/** The real clamp helper's own source, walked under its own path so its real lines are what correlate. */
function walkClampHelper(RequestPageSizeReader $reader): void
{
    $file = (new ReflectionClass(ListPageSize::class))->getFileName();
    expect($file)->toBeString();

    walkPageSize($reader, (string) file_get_contents((string) $file), (string) $file);
}

/** A caller body, as if it were the terminal's own method: `<statements>` then the paginating call. */
function walkCaller(RequestPageSizeReader $reader, string $body, string $receiver = 'Illuminate\\Http\\Request'): void
{
    walkPageSize($reader, "<?php\n".$body."\n", 'caller.php', $receiver);
}

it('follows a size argument through a local variable into a helper on another class', function (): void {
    $reader = new RequestPageSizeReader;
    walkCaller($reader, '$perPage = \Workbench\App\Support\ListPageSize::clamp($request, 15, 100);
$page = $this->paginate($perPage);');
    walkClampHelper($reader);

    $recovered = $reader->recovered();

    expect($recovered?->key)->toBe('per_page')
        // The read's fallback is the helper's own `$default` parameter, which belongs to whichever caller
        // supplied it — so there is no default to publish.
        ->and($recovered?->default)->toBeNull();
});

it('records the file the recovered fact was written in', function (): void {
    $reader = new RequestPageSizeReader;
    walkCaller($reader, '$perPage = \Workbench\App\Support\ListPageSize::clamp($request);
$page = $this->paginate($perPage);');
    walkClampHelper($reader);

    $reader->recovered();

    expect(array_map('basename', $reader->dependencyFiles()))->toContain('ListPageSize.php');
});

it('reads a request key written straight into the terminal, with its literal fallback', function (): void {
    $reader = new RequestPageSizeReader;
    walkCaller($reader, '$page = $this->paginate($request->integer("per_page", 15));');

    expect($reader->recovered()?->key)->toBe('per_page')
        ->and($reader->recovered()?->default)->toBe(15);
});

it('reads a request key through an inline clamp, whose bounds it deliberately drops', function (): void {
    // A clamp pins an out-of-range value to its nearest bound rather than rejecting it, so `minimum` /
    // `maximum` would tell a consumer their value is invalid when it is merely adjusted.
    $reader = new RequestPageSizeReader;
    walkCaller($reader, '$page = $this->paginate(max(1, min($request->integer("limit", 25), 100)));');

    expect($reader->recovered()?->key)->toBe('limit')
        ->and($reader->recovered()?->default)->toBe(25);
});

it('reads a size passed as a named argument', function (): void {
    $reader = new RequestPageSizeReader;
    walkCaller($reader, '$page = $this->paginate(perPage: $request->integer("per_page", 30));');

    expect($reader->recovered()?->key)->toBe('per_page');
});

it('correlates only the reads inside the callee the size argument named', function (): void {
    // `ListPageSize::ambiguous()` reads two keys in one body; `clamp()` reads one. Both live in the file
    // walked below, so a recovery that ignored source ranges would see three reads for either callee.
    $reader = new RequestPageSizeReader;
    walkCaller($reader, '$perPage = \Workbench\App\Support\ListPageSize::ambiguous($request);
$page = $this->paginate($perPage);');
    walkClampHelper($reader);

    expect($reader->recovered())->toBeNull();
});

it('keeps the key but drops a default two reads of it disagree on', function (): void {
    // Determinism: a default settled by whichever read the walk happened to see first would not be a fact.
    $reader = new RequestPageSizeReader;
    walkCaller($reader, '$perPage = \Workbench\App\Support\ListPageSize::repeated($request);
$page = $this->paginate($perPage);');
    walkClampHelper($reader);

    expect($reader->recovered()?->key)->toBe('per_page')
        ->and($reader->recovered()?->default)->toBeNull();
});

it('claims nothing for a size the endpoint does not read off the request', function (string $body): void {
    $reader = new RequestPageSizeReader;
    walkCaller($reader, $body);
    walkClampHelper($reader);

    expect($reader->recovered())->toBeNull();
})->with([
    // The model's own $perPage: no argument was written at all.
    'a bare terminal' => ['$page = $this->paginate();'],
    // Fixed at the call site — the case v0.5.0 stopped claiming a key for.
    'a literal size' => ['$page = $this->paginate(20);'],
    // A helper that takes the request and reads nothing off it.
    'a helper that reads nothing' => ['$perPage = \Workbench\App\Support\ListPageSize::fixed($request);
$page = $this->paginate($perPage);'],
    // A parameter of the enclosing method: the fixture `paginateList(int $perPage)` shape.
    'the enclosing method\'s own parameter' => ['$page = $this->paginate($perPage);'],
    // No such class, so no body to correlate against.
    'an unresolvable callee' => ['$perPage = \Nope\Missing::clamp($request);
$page = $this->paginate($perPage);'],
    // A second write means the variable names no single origin.
    'a variable written twice' => ['$perPage = \Workbench\App\Support\ListPageSize::clamp($request);
$perPage = 20;
$page = $this->paginate($perPage);'],
    // One variable hop only; a chain of them is dataflow guesswork.
    'a second variable hop' => ['$size = \Workbench\App\Support\ListPageSize::clamp($request);
$perPage = $size;
$page = $this->paginate($perPage);'],
    // `query()` with no key returns the whole bag and names nothing.
    'a keyless accessor' => ['$page = $this->paginate($request->query());'],
    // A non-literal key names nothing either.
    'a computed key' => ['$page = $this->paginate($request->integer($key));'],
]);

it('claims nothing for a terminal whose signature it does not know', function (): void {
    // A custom terminal's own argument order is unknown, so its arguments are never read positionally —
    // the vendor terminal it forwards to is reached by the trace anyway.
    $reader = new RequestPageSizeReader;
    walkCaller($reader, '$page = $this->paginateList($request->integer("per_page", 15));');

    expect($reader->recovered())->toBeNull();
});

it('claims nothing when the receiver of the read is not a request', function (): void {
    $reader = new RequestPageSizeReader;
    walkCaller($reader, '$page = $this->paginate($settings->integer("per_page", 15));', receiver: 'Workbench\App\Models\Form');

    expect($reader->recovered())->toBeNull();
});

it('declines a first-class callable rather than reading it as a size', function (): void {
    $reader = new RequestPageSizeReader;
    walkCaller($reader, '$fn = $this->paginate(...);');

    expect($reader->recovered())->toBeNull();
});
