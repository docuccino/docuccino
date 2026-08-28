<?php

declare(strict_types=1);

use Docuccino\Core\Document\PathItem;
use Docuccino\Core\Provenance\MessagePaths;
use Docuccino\Core\Provenance\RootRelativeSourcePathResolver;
use Docuccino\Core\Provenance\SourcePathResolver;

/**
 * The scrubber that lets a thrown message become a published diagnostic. The path ladder itself is
 * `RootRelativeSourcePathResolver`'s and is proved there; what these rows prove is which runs inside a
 * message are handed to it, and which are left alone.
 *
 * The two datasets below are the same dial read from both ends, and they belong together: every
 * relaxation that closes a leak is a chance to reduce something that was never a path, and every
 * exclusion that protects an author's text is a chance to publish a machine. So a change to the
 * matcher has to answer both lists at once — the first says nothing survives that names this machine,
 * the second says nothing is rewritten that the application actually wrote.
 *
 * Every shape a release note says is closed owes the first list a row, and the shapes left open owe the
 * second one, because a leak has already been counted closed on a reading of the code while the suite
 * had nothing that would have failed.
 */
it('reports one failure identically from two checkouts of the same code', function (): void {
    // The determinism promise, stated where it is easiest to break: two developers hit the same bug,
    // the thrown message names their own machine, and the diagnostic the document carries is the same
    // bytes for both. Windows on one side, so the drive letter is in the claim too.
    $thrown = static fn (string $root, string $separator): string => sprintf(
        'FormData::__construct(): Argument #1 ($name) must be of type string, int given, called in %s on line 10',
        $root.$separator.implode($separator, ['app', 'Http', 'Controllers', 'FormController.php']),
    );

    $alice = new MessagePaths(new RootRelativeSourcePathResolver('/home/alice/checkout'));
    $bob = new MessagePaths(new RootRelativeSourcePathResolver('C:\\Users\\bob\\dev\\checkout'));
    // A space in $HOME is ordinary on macOS and Windows, and a run that stopped at the space used to
    // leave the tail of the path standing while LOOKING scrubbed.
    $carol = new MessagePaths(new RootRelativeSourcePathResolver('/Users/ca rol/checkout'));

    expect($alice->relative($thrown('/home/alice/checkout', '/')))
        ->toBe($bob->relative($thrown('C:\\Users\\bob\\dev\\checkout', '\\')))
        ->toBe($carol->relative($thrown('/Users/ca rol/checkout', '/')))
        ->toBe('FormData::__construct(): Argument #1 ($name) must be of type string, int given, called in app/Http/Controllers/FormController.php on line 10');
});

it('scrubs every path in a message that names more than one', function (): void {
    $scrubbed = (new MessagePaths(new RootRelativeSourcePathResolver('/app/root')))
        ->relative('Could not copy /app/root/config/one.yaml to "/app/root/config/two.yaml"');

    expect($scrubbed)->toBe('Could not copy config/one.yaml to "config/two.yaml"');
});

it('leaves nothing in a published message that names the machine it was built on', function (string $case, string $message, string $expected): void {
    // Direction one. Every row is a shape a real build has produced, and every one of them used to
    // put an absolute path, a per-process counter or an install prefix into the document — which is a
    // determinism defect, since the same code on the next machine emits different bytes.
    $scrubbed = (new MessagePaths(new RootRelativeSourcePathResolver('/app/root')))->relative($message);

    expect($scrubbed)->toBe($expected)
        ->and($scrubbed)->not->toContain('/app/root/');
})->with([
    // The analyser is phar-resident, so its own internal errors name a path in ITS spelling. The
    // interior of an archive is the same wherever the archive sits, so only the archive relativises.
    [
        'the analyser\'s own phar',
        'Internal error in phar:///opt/brew/Cellar/phpstan/2.1.0/libexec/phpstan.phar/src/Analyser/NodeScopeResolver.php',
        'Internal error in phar://phpstan.phar/src/Analyser/NodeScopeResolver.php',
    ],
    [
        'a phar inside the project',
        'Internal error in phar:///app/root/vendor/phpstan/phpstan/phpstan.phar/src/Analyser/X.php',
        'Internal error in phar://vendor/phpstan/phpstan/phpstan.phar/src/Analyser/X.php',
    ],
    ['another local stream wrapper', 'Could not open file:///app/root/app/X.php', 'Could not open file://app/X.php'],
    // `::class` on an anonymous class is a base name, a NUL byte, the absolute file, the line, and a
    // counter of the anonymous classes the PROCESS declared first. Two runs need not agree on the last.
    [
        'an anonymous class inside a sentence',
        "Expected FormData, got class@anonymous\0/app/root/app/Support/Inline.php:18\$1f",
        'Expected FormData, got class@anonymous declared in app/Support/Inline.php:18',
    ],
    // A UNC path and a drive glued to a word character are shapes nothing but a path has, so both are
    // reduced whatever precedes them.
    ['a UNC share', 'Failed to read \\\\SERVER\\share\\app\\Http\\X.php', 'Failed to read X.php'],
    ['a drive glued to a word', 'read atC:\\Users\\dev\\app\\X.php ok', 'read atX.php ok'],
    // A path outside the project and outside any package: no root to strip, so the name is all that
    // may survive. This is the ladder's documented degradation, reached through a message.
    [
        'a path under no root at all',
        'file_get_contents(/elsewhere/cache/acme/Reader.php): Failed to open stream',
        'file_get_contents(Reader.php): Failed to open stream',
    ],
    // A directory one segment under the root relativises to exactly its own basename, which is also what
    // the ladder answers when it recognised nothing — so a predicate reading the ANSWER cannot tell the
    // two apart, and published these whole. They are the framework's own directories, so the failure
    // reaching a route diagnostic is an ordinary one: a storage permission, an unreadable vendor tree.
    ['a directory one segment under the root', 'mkdir(/app/root/storage): Permission denied', 'mkdir(storage): Permission denied'],
    ['a package tree the build could not read', 'scandir(/app/root/vendor): Permission denied', 'scandir(vendor): Permission denied'],
    ['a file with no extension under the root', 'require(/app/root/artisan) failed', 'require(artisan) failed'],
    // A colon is legal in a POSIX directory name, and a run cut in front of one no longer ends in a
    // filename — which is all reason 4 has to go on. So the two rows below were published whole while
    // the same paths without the colon reduced to their names.
    [
        'a colon in a directory name',
        'file_get_contents(/Users/dev/Caches/a:b/Reader.php): Failed to open stream',
        'file_get_contents(Reader.php): Failed to open stream',
    ],
    [
        'a timestamped directory, which spells two colons',
        'file_get_contents(/Users/dev/Caches/2026-08-25T10:30:00/Reader.php): Failed to open stream',
        'file_get_contents(Reader.php): Failed to open stream',
    ],
    ['a colon in a directory under the root', 'require(/app/root/storage/a:b/X.php) failed', 'require(storage/a:b/X.php) failed'],
    // The root itself has nothing left after the strip, and the one thing `relative()` can answer with
    // there — the name of the directory the checkout sits in — is a different string on every machine.
    ['the project root itself', 'mkdir(/app/root): Permission denied', 'mkdir(): Permission denied'],
]);

it('tells a root it recognised from a root it never found', function (): void {
    // The predicate reason 3 turns on, stated as the one pair that isolates it: the two paths differ only
    // in whether a root accounts for them, and the ladder answers BOTH with a bare `storage`. So an
    // answer longer than the basename is not proof a prefix was stripped — asking with segments the
    // ladder cannot have invented is (MessagePaths::PROBE).
    $paths = new MessagePaths(new RootRelativeSourcePathResolver('/app/root'));

    expect($paths->relative('mkdir(/app/root/storage) failed'))->toBe('mkdir(storage) failed')
        ->and($paths->relative('mkdir(/elsewhere/storage) failed'))->toBe('mkdir(/elsewhere/storage) failed');
});

it('crosses a space inside a directory name, and stops at one that starts a sentence', function (string $case, string $message, string $expected): void {
    // Both ends of the space tolerance. A `$HOME` with a space in it is the ordinary macOS and Windows
    // case and its segments have text against both separators; a sentence carrying on after a path has no
    // separator left, and a second path in the same sentence puts a space right against the next one.
    expect((new MessagePaths(new RootRelativeSourcePathResolver('/app/root')))->relative($message))
        ->toBe($expected);
})->with([
    ['a space inside a directory name', 'read /Users/ca rol/Library/pkg/Reader.php failed', 'read Reader.php failed'],
    ['a sentence carrying on after a path', 'See /docs/reference/configuration and open app.php', 'See /docs/reference/configuration and open app.php'],
    ['a second path in the same sentence', 'See /docs/reference/configuration and /docs/other/x.php', 'See /docs/reference/configuration and x.php'],
]);

it('reduces a path under a $HOME that has a space in it, wherever under it the path sits', function (string $case, string $message, string $expected): void {
    // The shape was closed for exactly one position — a path under the base path itself — and left
    // standing everywhere else under the same home, which is where a global cache and an installed phar
    // sit. So the message LOOKED scrubbed while naming the machine's own user.
    $scrubbed = (new MessagePaths(new RootRelativeSourcePathResolver('/Users/ca rol/checkout')))->relative($message);

    expect($scrubbed)->toBe($expected)
        ->and($scrubbed)->not->toContain('ca rol');
})->with([
    ['under the base path', 'read /Users/ca rol/checkout/app/X.php failed', 'read app/X.php failed'],
    ['under the home, outside every root', 'read /Users/ca rol/Library/Caches/pkg/Reader.php failed', 'read Reader.php failed'],
    ['a phar installed under the home', 'Internal error in phar:///Users/ca rol/bin/phpstan.phar/src/X.php', 'Internal error in phar://phpstan.phar/src/X.php'],
]);

it('leaves alone every run that a machine did not put there', function (string $case, string $message): void {
    // Direction two, and the one that must be impossible to get wrong: an over-scrub does not leak a
    // machine, it makes the product state something the application never said. Each row here is text
    // an author wrote or a tool quoted back, and each was reduced to its last segment before.
    expect((new MessagePaths(new RootRelativeSourcePathResolver('/app/root')))->relative($message))
        ->toBe($message);
})->with([
    // Reachable on EVERY build: the validator throws `Unresolved reference: {$ref}` and the pointer is
    // the only thing naming the component that could not be resolved.
    ['a JSON pointer', 'Unresolved reference: #/components/schemas/User/properties/password'],
    ['a $ref in quotes', 'Points at $ref "#/components/schemas/Order/properties/total".'],
    // A malformed overlay makes the YAML parser quote the offending line back. Reduce it and the
    // diagnostic sends the author hunting for text nobody wrote.
    ['a route signature a parser quoted', 'Malformed inline YAML at line 3 (near "GET /api/forms/{form}").'],
    ['a route signature with no parameter', 'Unknown route GET /api/forms'],
    ['a route signature with a format suffix', 'Unknown route GET /api/users.json'],
    // Reducing a rule states a DIFFERENT, valid-looking rule — the worst of the four, because nothing
    // downstream can tell it was rewritten.
    ['a rule whose regex holds a separator', 'Rule "regex:/^\\d+\\/\\d+$/" could not be read'],
    ['a rule whose regex holds none', 'Rule "regex:/^[a-z]+$/" could not be read'],
    ['a root-relative documentation link', 'See /docs/reference/configuration for the key.'],
    // The price of reason 4, and the shape that stays open because of it: a directory under no root
    // names no file, and nothing tells it from the link above. Reducing it would state a path the
    // application never wrote, which is the direction that must be impossible — so it stands, spaced
    // `$HOME` and all.
    ['a directory under no root at all', 'scandir(/Users/ca rol/Library/Caches) failed'],
    ['a home-relative path', 'Reading ~/Projects/app/config.php failed'],
    ['a URL', 'GET https://api.example.com/v1/forms returned 500'],
    ['a URL naming a file', 'Fetching https://cdn.example.com/assets/app.js failed'],
    ['a namespaced class', 'Class App\\Http\\Controllers\\FormController does not exist'],
    ['a media type', 'Body is application/vnd.api+json'],
    ['a date', 'Expected 2026/08/25 style'],
    ['a single-segment absolute path', 'The directory /tmp is not writable'],
    ['no path at all', 'Undefined array key "form"'],
    // The other end of the colon tolerance. A colon joins a run only where more path follows it before
    // the next delimiter, so each of these keeps the colon as the delimiter it is: nothing after it
    // reaches a separator.
    ['a clock time beside no path', 'Analysis gave up at 10:30:00'],
    ['a rule naming a range', 'Rule "between:1,10" could not be read'],
    ['a host and a port', 'Could not reach cache at redis:6379'],
]);

it('takes every local stream wrapper as proof of a local tail, and no other scheme', function (string $prefix, bool $reduced): void {
    // The whole table, both halves, plus a scheme it has never decided about. A wrapper is the one
    // positive proof a run is a path, so a scheme missing from the table leaks and a scheme wrongly in
    // it reduces something that was never a file.
    //
    // Every row here spells a LOCAL tail, which is the half a flat `scheme => bool` can still state:
    // proof stops at the first character of what follows the scheme, so the other half — a tail that
    // is itself a URL — is a dataset of its own below, derived from the same table.
    $message = 'Could not open '.$prefix.'/app/root/app/Support/Inline.php';

    expect((new MessagePaths(new RootRelativeSourcePathResolver('/app/root')))->relative($message))
        ->when($reduced, fn ($it) => $it->toBe('Could not open '.$prefix.'app/Support/Inline.php'))
        ->when(! $reduced, fn ($it) => $it->toBe($message));
})->with([
    ['file://', true],
    ['phar://', true],
    ['zip://', true],
    ['glob://', true],
    ['compress.zlib://', true],
    ['compress.bzip2://', true],
    // The other half of the table: a URL names a host, and none of the last three names a file this
    // machine holds.
    ['http://', false],
    ['https://', false],
    ['ftp://', false],
    ['ftps://', false],
    ['php://', false],
    ['data://', false],
    ['sqlsrv://', false],
    // A scheme the table has decided nothing about — an object store a package registers, say. Proof
    // is what the table grants, so an undecided scheme falls through to shape, which this run has
    // none of: the degradation is the safe direction, and the guard below is what makes it a decision
    // somebody takes rather than one nobody notices.
    ['s3://', false],
]);

/*
 * The dataset above proves the rows it lists, and the rows are the table read back. This reads the
 * source of truth for what the running PHP has REGISTERED, so a wrapper the reduction would meet and
 * has no decision about fails here rather than shipping — a leak in one direction or an over-scrub in
 * the other, both silent.
 */
it('has a decision about every stream wrapper the running PHP has registered', function (): void {
    /** @var array<string, bool> $decided */
    $decided = (new ReflectionClass(MessagePaths::class))->getReflectionConstant('WRAPPERS')?->getValue();

    // The guard itself, so the refusal below executes it rather than restating it. It answers with the
    // decision each unaccounted scheme is owed, because the population is a fact about the machine:
    // an extension loaded on a developer's box and absent from CI arrives here first, and the reader
    // has to be told what to do about it rather than left with a bare scheme name.
    $undecided = static fn (): string => implode('; ', array_map(
        static fn (string $scheme): string => sprintf(
            '%s:// is registered on this machine and decided nowhere: add it to MessagePaths::WRAPPERS as '
            .'true where it can name nothing but a file on this machine, false where it names a host or '
            .'names no file at all. An extension present here and absent in CI reaches this too, and the '
            .'decision is owed either way.',
            $scheme,
        ),
        array_diff(stream_get_wrappers(), array_keys($decided)),
    ));

    expect($undecided())->toBe('');

    // Anti-vacuity, both ends: a `stream_get_wrappers()` that stopped answering and a table gutted to
    // nothing agree with each other on an empty diff, forever.
    // The comparison is one-directional on purpose: a PHP built without openssl registers no `https`,
    // so a table wider than the machine is ordinary, and only a machine wider than the table is a
    // defect.
    expect(stream_get_wrappers())->toContain('file', 'php', 'http')
        ->and(count($decided))->toBeGreaterThanOrEqual(8)
        ->and(array_keys(array_filter($decided)))->toContain('file', 'phar', 'glob', 'compress.zlib', 'compress.bzip2');

    // The guard executed. A wrapper registered under a scheme the table cannot know is exactly the
    // case it exists to refuse, and refusing it is not something a comment may claim.
    expect(stream_wrapper_register('docuccino.probe', stdClass::class))->toBeTrue();

    try {
        expect($undecided())->toContain('docuccino.probe://')->toContain('MessagePaths::WRAPPERS');
    } finally {
        stream_wrapper_unregister('docuccino.probe');
    }

    expect($undecided())->toBe('');
});

it('takes no wrapper as proof once its tail is itself a URL', function (string $scheme): void {
    // The half `true` never covered. The compression wrappers filter another STREAM rather than
    // naming a file, so where `file://` takes a path they take a URL — `compress.zlib://http://…`
    // reads a HOST, and proof reduces it to a basename, which is the product stating an address the
    // application never wrote. Every proof scheme is a row because every one reads the narrowing: what
    // follows `file://` is a path by definition, so a `file://http://…` never named a local file
    // either, and one rule is cheaper than an exception.
    //
    // The local path in the same sentence still goes, which is what says the refusal belongs to the
    // RUN and not to the message: declining to open on a nested URL leaves the rest of it to scrub.
    $paths = new MessagePaths(new RootRelativeSourcePathResolver('/app/root'));

    expect($paths->relative('Could not open '.$scheme.'://http://example.com/archive.gz beside /app/root/app/X.php'))
        ->toBe('Could not open '.$scheme.'://http://example.com/archive.gz beside app/X.php')
        // A `php://filter` tail is the leak the narrowing closes, and it is a leak in every one of
        // these schemes rather than in the two that prompted the change. Proof took the whole run to
        // the ladder, the ladder had nothing ABSOLUTE to answer — `php://…` is not — and the machine
        // path behind `resource=` was published verbatim. Declining to open there hands it back to
        // shape, which reaches it exactly as it reaches a bare `php://filter`.
        ->and($paths->relative('Could not open '.$scheme.'://php://filter/read=zlib.inflate/resource=/app/root/app/X.php'))
        ->toBe('Could not open '.$scheme.'://php://filter/read=zlib.inflate/resource=app/X.php');
})->with(function (): array {
    /** @var array<string, bool> $wrappers */
    $wrappers = (new ReflectionClass(MessagePaths::class))->getReflectionConstant('WRAPPERS')?->getValue();

    $rows = [];

    foreach (array_keys(array_filter($wrappers)) as $scheme) {
        $rows[$scheme.'://'] = [$scheme];
    }

    return $rows;
});

it('keeps the host of a remote-targeted compression run whatever the ladder would answer', function (): void {
    // The guard executed rather than asserted, and the reason the rows above are not enough on their
    // own. `RootRelativeSourcePathResolver` hands a non-absolute run straight back, and every
    // `scheme://host/…` tail is non-absolute — so the shipped pair would keep the host even with the
    // narrowing removed, and would prove nothing about it. The ladder is an interface any adapter may
    // implement, so this asks with one that answers a basename for anything: the remote form must
    // still keep its host, and the local form must still reduce.
    $eager = new class implements SourcePathResolver
    {
        public function relative(string $file): string
        {
            return basename($file);
        }
    };

    expect((new MessagePaths($eager))->relative('Could not open compress.zlib://http://example.com/archive.gz'))
        ->toBe('Could not open compress.zlib://http://example.com/archive.gz')
        ->and((new MessagePaths($eager))->relative('Could not open compress.bzip2://http://example.com/a.bz2'))
        ->toBe('Could not open compress.bzip2://http://example.com/a.bz2')
        // Unnarrowed, both of those answered `compress.zlib://archive.gz` — a file this machine was
        // said to hold, for a run that named somebody else's host.
        ->and((new MessagePaths($eager))->relative('Could not open compress.zlib:///home/alice/cache.gz'))
        ->toBe('Could not open compress.zlib://cache.gz');
});

it('leaves a nest of wrappers whole, and reaches a filter resource by shape as before', function (string $case, string $message, string $expected): void {
    // The two shapes that make a naive "is the next thing a scheme" test wrong, decided rather than
    // stumbled into. A nest of proof schemes is left WHOLE rather than unwrapped a level at a time:
    // the innermost tail may still be a host, PHP will not open a nest anyway (the inner stream has to
    // be castable to a descriptor), and the traded leak is the direction that may be traded — the
    // second row would otherwise publish `x.bz2` for a run that named a host.
    //
    // `php://filter` is not proof at all — `php` is false — so its `resource=` is reached by shape
    // exactly as it was, and the narrowing does not touch it.
    expect((new MessagePaths(new RootRelativeSourcePathResolver('/app/root')))->relative($message))
        ->toBe($expected);
})->with([
    [
        'a nest ending at a local file, left standing',
        'Could not open compress.zlib://compress.bzip2:///home/alice/x.bz2',
        'Could not open compress.zlib://compress.bzip2:///home/alice/x.bz2',
    ],
    [
        'a nest ending at a host',
        'Could not open compress.zlib://compress.bzip2://http://example.com/x.bz2',
        'Could not open compress.zlib://compress.bzip2://http://example.com/x.bz2',
    ],
    [
        'a filter naming a local resource',
        'Could not open php://filter/read=zlib.inflate/resource=/app/root/app/X.php',
        'Could not open php://filter/read=zlib.inflate/resource=app/X.php',
    ],
]);

it('reads the wrapper table the same way once a brace is in the run', function (string $scheme, bool $proof): void {
    // A brace says URI template, but only where the run does not already open with proof. Nothing a
    // template is spelled with opens `<scheme>://`: a route signature, a path template and a JSON
    // pointer all start at a `/` or a `#`, and a wrapper scheme is proof from the FIRST character. So
    // the brace exclusion may yield to it without ever admitting a template, and `{Support,Http}` in a
    // glob is read as the shell glob it is instead of keeping the absolute prefix in front of it — the
    // brace pattern being the most ordinary thing a `glob://` run is spelled with.
    //
    // The false half gains nothing: those name a host or no file at all, so a braced one is left
    // exactly as it arrived. Derived from the table rather than spelled again, so a scheme added to
    // either half is answered here too; the anti-vacuity assertions in the guard above are what keep
    // the derivation from emptying out and passing forever.
    $message = 'Could not open '.$scheme.':///app/root/app/{Support,Http}/*.php';

    expect((new MessagePaths(new RootRelativeSourcePathResolver('/app/root')))->relative($message))
        ->toBe($proof ? 'Could not open '.$scheme.'://app/{Support,Http}/*.php' : $message);
})->with(function (): array {
    /** @var array<string, bool> $wrappers */
    $wrappers = (new ReflectionClass(MessagePaths::class))->getReflectionConstant('WRAPPERS')?->getValue();

    $rows = [];

    foreach ($wrappers as $scheme => $proof) {
        $rows[$scheme.'://'] = [$scheme, $proof];
    }

    return $rows;
});

it('leaves a braced run alone where nothing but the brace opens it', function (string $case, string $message): void {
    // The other side of the same flip, and the one that must be impossible to get wrong. Each row is a
    // template an author wrote or a tool quoted back: none opens with a shape the class calls proof,
    // and no root the ladder recognises accounts for the text in front of the brace — so there are two
    // ways in and the brace refuses both, which is the whole reason these keep every character.
    expect((new MessagePaths(new RootRelativeSourcePathResolver('/app/root')))->relative($message))
        ->toBe($message);
})->with([
    ['a bare path template', 'Unknown route /api/users/{user}'],
    ['a path template naming a file', 'Unknown route /api/users/{user}/avatar.png'],
    ['a route signature a parser quoted', 'Malformed inline YAML at line 3 (near "GET /api/forms/{form}").'],
    ['a JSON pointer at a placeholder', 'Unresolved reference: #/components/schemas/User/properties/{name}'],
    ['a URL whose path is templated', 'GET http://api.example.com/{region}/forms returned 500'],
    ['a server URL whose host is templated', 'GET https://{region}.api.example.com/v1/forms returned 500'],
    ['an array shape quoted in a message', 'Expected array{id: int, name: string}'],
    // Two rows for the anchor specifically: a root is a PREFIX, not a substring, and the run has to
    // be under it rather than beside it.
    ['a sibling of the root, one character out', 'Could not open /app/rooted/{a,b}/*.php'],
    ['a directory named like the root', 'Could not open /app/root.bak/{a,b}/*.php'],
]);

it('will not let a one-segment root overrule a brace, because a route prefix spells one', function (string $case, string $message): void {
    // The anchor's own limit, and the reason it is not simply "the ladder recognised a root". A
    // container puts the checkout at `/app`, and `/app` is equally a route prefix an application
    // mounts — so under a one-segment root a template IS anchored, and reducing it would state a route
    // the application never wrote. Two segments is the line, the one `redact()` already draws for the
    // same reason. What that costs is the row below it: a real path under such a root keeps its
    // machine word, which is the direction that may be traded.
    expect((new MessagePaths(new RootRelativeSourcePathResolver('/app')))->relative($message))
        ->toBe($message);
})->with([
    ['a template the root is a prefix of', 'Unknown route /app/users/{user}'],
    ['a template with a format suffix', 'Unknown route /app/users/{user}.json'],
    ['a real path under the same root', 'Could not open /app/config/{a,b}/*.php'],
]);

it('reduces a braced run a recognised root accounts for, with no wrapper in front of it', function (string $case, string $message, string $expected): void {
    // The half a scheme cannot answer: a BARE absolute path carrying a brace, which used to be
    // published whole because the brace refused it before anything else was asked. What admits it is
    // not the brace's shape but the text in FRONT of it — a root the ladder recognised, so the prefix
    // being removed is a directory this machine was configured from and the strip cannot invent text.
    // The braces themselves survive wherever they stood, exactly as they do behind a wrapper.
    $scrubbed = (new MessagePaths(new RootRelativeSourcePathResolver('/app/root')))->relative($message);

    expect($scrubbed)->toBe($expected)
        ->and($scrubbed)->not->toContain('/app/root');
})->with([
    [
        'a brace expansion under the root',
        'Could not open /app/root/app/{Support,Http}/*.php',
        'Could not open app/{Support,Http}/*.php',
    ],
    [
        'a brace in the first surviving segment',
        'Could not open /app/root/{tenant}/routes.php',
        'Could not open {tenant}/routes.php',
    ],
    [
        'a path the root IS, less its braced tail',
        'Failed to read /app/root/{a,b}',
        'Failed to read {a,b}',
    ],
]);

it('reduces a braced run proof opened, with no root to account for it', function (string $case, string $base, string $message, string $expected): void {
    // Proof outranks the brace whichever shape carries it, and a drive and a UNC share are proof from
    // the first character exactly as a wrapper is: no route signature, path template or JSON pointer
    // is spelled `C:\` or `\\host\`. So these reach the ladder even where no root accounts for them,
    // and the degradation takes the machine's user with it — which is the answer a bare POSIX run of
    // the same shape cannot have, having nothing but the brace to go on.
    $scrubbed = (new MessagePaths(new RootRelativeSourcePathResolver($base)))->relative($message);

    expect($scrubbed)->toBe($expected)
        ->and($scrubbed)->not->toContain('bob');
})->with([
    [
        'a drive under the checkout',
        'C:\\Users\\bob\\dev\\checkout',
        'Could not open C:\\Users\\bob\\dev\\checkout\\app\\{a,b}\\X.php',
        'Could not open app/{a,b}\\X.php',
    ],
    [
        'a drive outside every root',
        'C:\\Users\\bob\\dev\\checkout',
        'Could not open C:\\Users\\bob\\secret\\{a,b}\\X.php',
        'Could not open {a,b}\\X.php',
    ],
    [
        'a UNC share',
        '/app/root',
        'Could not open \\\\build01\\share\\{a,b}\\X.php',
        'Could not open {a,b}\\X.php',
    ],
]);

it('publishes a braced run whole where no root and no proof reach it, and that is the trade', function (): void {
    // The leak that is left, recorded rather than discovered: outside every root a bare POSIX run has
    // nothing but its shape, and shape is what cannot tell `/api/users/{user}/avatar.png` from a file.
    // Degrading this one to its basename would degrade that one too, so the machine word stays. The
    // second expectation is the row that would have to change first, and it is the one that must not.
    $carol = new MessagePaths(new RootRelativeSourcePathResolver('/Users/ca rol/checkout'));

    expect($carol->relative('Could not open /Users/ca rol/secret/{Support,Http}/*.php'))
        ->toBe('Could not open /Users/ca rol/secret/{Support,Http}/*.php')
        ->and($carol->relative('Unknown route /api/users/{user}/avatar.png'))
        ->toBe('Unknown route /api/users/{user}/avatar.png')
        // The same run without the brace does degrade, which is what the brace is costing here.
        ->and($carol->relative('Could not open /Users/ca rol/secret/Support/x.php'))
        ->toBe('Could not open x.php');
});

it('reduces a run a root accounts for where the backslash is in the sentence, not the path', function (string $case, string $message, string $expected): void {
    // The brace's sibling, and the same defect: an exclusion reads the WHOLE space-crossing run, so a
    // backslash anywhere past the path refused the path too. A thrown message naming a file and a
    // namespaced class in one sentence is the most ordinary shape PHP produces, and every one of them
    // published the checkout whole. The anchor answers it the same way — the text in front of the
    // backslash is under a root the ladder recognised, so the strip is the ladder's, not a guess.
    $scrubbed = (new MessagePaths(new RootRelativeSourcePathResolver('/app/root')))->relative($message);

    expect($scrubbed)->toBe($expected)
        ->and($scrubbed)->not->toContain('/app/root');
})->with([
    [
        'a class after the file and the line',
        'Failed in /app/root/app/X.php on line 3 for App\\Foo',
        'Failed in app/X.php on line 3 for App\\Foo',
    ],
    [
        'a class against the file',
        'Could not open /app/root/app/X.php App\\Foo',
        'Could not open app/X.php App\\Foo',
    ],
    [
        'a brace and a backslash in one run',
        'Failed in /app/root/app/{a,b}/X.php for App\\Foo',
        'Failed in app/{a,b}/X.php for App\\Foo',
    ],
]);

it('still refuses a backslash run no root accounts for', function (string $case, string $message): void {
    // The exclusion is not weakened, only anchored: with no root in front of it a backslash still says
    // regex or JSON string, and those rows are the ones that must never be rewritten. The third is the
    // leak that buys it — the same trade the braced run makes, in the same direction.
    expect((new MessagePaths(new RootRelativeSourcePathResolver('/Users/ca rol/checkout')))->relative($message))
        ->toBe($message);
})->with([
    ['a rule whose regex holds a separator', 'Rule "regex:/^\\d+\\/\\d+$/" could not be read'],
    ['a pattern rooted nowhere', 'Refused /some/where/\\d+/x.php as a pattern'],
    ['a path outside every root, followed by a class', 'Failed in /Users/ca rol/secret/X.php for App\\Foo'],
]);

it('redacts a machine root out of a braced run without needing the anchor at all', function (): void {
    // Why the anchor is asked of the ladder's roots and not of `machineRoots()`: the temp directory and
    // the include path are redacted LITERALLY, after the run pass, so a brace never protected them.
    // Adding them to the anchor would be a second way to say what this already says.
    $message = sprintf('Could not open %s/build/{a,b}/x.php', sys_get_temp_dir());
    $scrubbed = (new MessagePaths(new RootRelativeSourcePathResolver('/app/root')))->relative($message);

    expect($scrubbed)->toBe('Could not open build/{a,b}/x.php')
        ->and($scrubbed)->not->toContain(sys_get_temp_dir());
});

it('reduces the machine half of a braced wrapper run and leaves the braces where they stood', function (string $case, string $message, string $expected): void {
    // The brace can sit anywhere in the run, including inside the part that survives the strip. What a
    // recognised root buys is a prefix strip, which cannot invent text — so a templated directory under
    // the root keeps every character it had while the machine word in front of it goes.
    $scrubbed = (new MessagePaths(new RootRelativeSourcePathResolver('/app/root')))->relative($message);

    expect($scrubbed)->toBe($expected)
        ->and($scrubbed)->not->toContain('/app/root');
})->with([
    [
        'a brace in the surviving half',
        'Could not open file:///app/root/{tenant}/routes.php',
        'Could not open file://{tenant}/routes.php',
    ],
    [
        'a brace inside an archive',
        'Internal error in phar:///app/root/vendor/acme/acme.phar/{a,b}/X.php',
        'Internal error in phar://vendor/acme/acme.phar/{a,b}/X.php',
    ],
    [
        'a glob under the root',
        'Could not open glob:///app/root/app/{Support,Http}/*.php',
        'Could not open glob://app/{Support,Http}/*.php',
    ],
]);

it('scrubs a braced wrapper run that no root accounts for', function (): void {
    // The degradation, reached through a brace: outside every root there is no prefix to strip, so the
    // basename is all that may survive — and the machine's own user is what a `glob://` pattern under a
    // `$HOME` would otherwise have published.
    $scrubbed = (new MessagePaths(new RootRelativeSourcePathResolver('/Users/ca rol/checkout')))
        ->relative('Could not open glob:///Users/ca rol/secret/{Support,Http}/*.php');

    expect($scrubbed)->toBe('Could not open glob://{Support,Http}/*.php')
        ->and($scrubbed)->not->toContain('ca rol');
});

it('reads a route signature after every method a route can carry', function (string $method): void {
    // The whole table, read from the methods the DOCUMENT carries rather than spelled again here: a
    // method the scrubber's own list is short of reduces a signature to its last segment, which is the
    // over-scrub direction. A signature is quoted back by a YAML parser verbatim, so the method in
    // front of it is what says "this is a route" — and the format suffix is what would otherwise make
    // it look like a file.
    $message = sprintf('Unknown route %s /api/users.json', $method);

    expect((new MessagePaths(new RootRelativeSourcePathResolver('/app/root')))->relative($message))
        ->toBe($message);
})->with(array_map('strtoupper', PathItem::METHODS));

it('has a route method to read a signature after', function (): void {
    // Anti-vacuity for the dataset above, now that it is derived: a `PathItem::METHODS` that stopped
    // answering would leave it with no rows at all and pass forever.
    expect(PathItem::METHODS)->toContain('get', 'post', 'delete')
        ->and(count(PathItem::METHODS))->toBeGreaterThanOrEqual(7);
});

it('redacts the install prefix PHP appends to a failed include', function (): void {
    // The tail PHP puts on every failed include is a colon-separated list of directories, which no
    // path grammar can be relaxed enough to read without reading a `regex:/…/` the same way. They are
    // prefixes this process can name for itself, so they go literally instead.
    $entries = array_values(array_filter(
        explode(PATH_SEPARATOR, (string) ini_get('include_path')),
        static fn (string $entry): bool => str_starts_with($entry, '/') && substr_count($entry, '/') >= 2,
    ));

    // Anti-vacuity: nothing is proved on a machine whose include_path holds no absolute directory.
    expect($entries)->not->toBe([]);

    $message = sprintf("Failed opening required 'x.php' (include_path='%s')", implode(PATH_SEPARATOR, $entries));
    $scrubbed = (new MessagePaths(new RootRelativeSourcePathResolver('/app/root')))->relative($message);

    foreach ($entries as $entry) {
        expect($scrubbed)->not->toContain($entry);
    }
});

it('redacts a machine root however deep it happens to be', function (string $case, string $configured): void {
    // The row above reads THIS machine's include_path, so how shallow a prefix it proves anything about
    // is a fact about the developer's PHP install: a two-segment prefix survived whole while a
    // three-segment one was redacted, which is the same code emitting different bytes for the machine it
    // ran on. Here the prefix is the input, so both depths are answered on every machine.
    $restore = (string) ini_get('include_path');

    try {
        ini_set('include_path', '.'.PATH_SEPARATOR.$configured);
        $scrubbed = (new MessagePaths(new RootRelativeSourcePathResolver('/app/root')))->relative(sprintf(
            "Failed opening required 'x.php' (include_path='.%s%s')",
            PATH_SEPARATOR,
            $configured,
        ));
    } finally {
        ini_set('include_path', $restore);
    }

    expect($scrubbed)->not->toContain($configured);
})->with([
    ['a two-segment prefix', '/opt/php'],
    ['a three-segment prefix', '/opt/brew/php'],
    ['a deep prefix', '/opt/homebrew/Cellar/php/8.5.0/share/php/pear'],
    // One segment stays: `/tmp` is a word our own sentences spell, and redaction is a literal replace
    // with nothing to tell the two apart.
]);

it('leaves a one-segment machine root alone, because prose spells one', function (): void {
    $restore = (string) ini_get('include_path');

    try {
        ini_set('include_path', '.'.PATH_SEPARATOR.'/tmp');
        $scrubbed = (new MessagePaths(new RootRelativeSourcePathResolver('/app/root')))
            ->relative('The directory /tmp is not writable');
    } finally {
        ini_set('include_path', $restore);
    }

    expect($scrubbed)->toBe('The directory /tmp is not writable');
});

it('scrubs the file a callable label names and leaves the rest of the label alone', function (string $case, string $label, string $expected): void {
    // The other kind of fragment that reaches it: not a thrown message but a locator for something
    // anonymous, where the file IS the name. Whichever side of the file the locator sits on, the run
    // ends at the `:` before the line, so what the author needs — a file they can open and a line to
    // open it at — survives the scrub.
    expect((new MessagePaths(new RootRelativeSourcePathResolver('/app/root')))->relative($label))
        ->toBe($expected);
})->with([
    ['a closure named after its file', '/app/root/bootstrap/app.php::closure@42', 'bootstrap/app.php::closure@42'],
    ['a closure named before its file', 'closure@/app/root/bootstrap/app.php:42', 'closure@bootstrap/app.php:42'],
    ['a closure in a package', '/app/root/vendor/acme/src/Handlers.php::closure@7', 'vendor/acme/src/Handlers.php::closure@7'],
    ['a class and a method', 'App\\Exceptions\\Renderer::__invoke', 'App\\Exceptions\\Renderer::__invoke'],
    ['a label already relative', 'bootstrap/app.php::closure@42', 'bootstrap/app.php::closure@42'],
    ['a label naming one segment', 'app.php::closure@42', 'app.php::closure@42'],
]);
