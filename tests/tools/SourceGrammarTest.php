<?php

declare(strict_types=1);

/*
 * The shared PHP source grammar the repo's source-reading guards derive their populations through
 * ({@see phpDeclaredClasses()}, {@see phpReferencedClasses()}, {@see phpCalledMethods()},
 * {@see phpStaticCalls()}).
 *
 * A guard is only worth the spellings it recognises, and a spelling it misses looks exactly like a
 * pass — so every spelling claimed above is written out here and run through the reader, including the
 * plainest one, so a reader that stopped seeing anything fails instead of agreeing with an empty set.
 */

it('names a class however its declaration is spelled', function (string $declaration, string $expected): void {
    $source = "<?php\n\nnamespace Probe\\Grammar;\n\n".$declaration;

    expect(phpDeclaredClasses($source))->toBe([$expected]);
})->with([
    'plain' => ['class Plain {}', 'Probe\Grammar\Plain'],
    'final' => ['final class Fin {}', 'Probe\Grammar\Fin'],
    'abstract' => ['abstract class Abs {}', 'Probe\Grammar\Abs'],
    'readonly' => ['readonly class Ro {}', 'Probe\Grammar\Ro'],
    'final readonly' => ['final readonly class Fro {}', 'Probe\Grammar\Fro'],
    'readonly final' => ['readonly final class Rof {}', 'Probe\Grammar\Rof'],
    'attributed' => ["#[\\Attribute]\nfinal class Att {}", 'Probe\Grammar\Att'],
    'declaration split over lines' => ["final\nclass Split\n    extends \\ArrayObject {}", 'Probe\Grammar\Split'],
    'indented inside a block' => ["if (true) {\n    class Nested {}\n}", 'Probe\Grammar\Nested'],
]);

it('takes neither an anonymous class nor a ::class constant for a declaration', function (string $source): void {
    expect(phpDeclaredClasses("<?php\n\nnamespace Probe\\Grammar;\n\n".$source))->toBe([]);
})->with([
    'anonymous' => ['$x = new class {};'],
    'anonymous with a base' => ['$x = new class extends \ArrayObject {};'],
    'the class constant' => ['$x = \ArrayObject::class;'],
    'an interface' => ['interface Contract {}'],
    'a trait' => ['trait Shared {}'],
    'an enum' => ['enum Colour {}'],
    'the word in a string' => ["\$x = 'final class NotReal {}';"],
]);

it('resolves a referenced class however it is written', function (string $body): void {
    $source = "<?php\n\nnamespace Probe\\Grammar;\n\nuse Illuminate\\Http\\Request;\nuse Illuminate\\Http\\Request as Req;\n\nfinal class Ref\n{\n    public function run(): void\n    {\n".$body."\n    }\n}\n";

    expect(phpReferencedClasses($source))->toContain('Illuminate\Http\Request');
})->with([
    'imported short name' => ['        $x = Request::capture();'],
    'the class constant' => ['        $x = Request::class;'],
    'an aliased import' => ['        $x = Req::class;'],
    'fully qualified inline' => ['        $x = \Illuminate\Http\Request::class;'],
    'instantiated' => ['        $x = new Request;'],
    'an instanceof' => ['        $x = $this instanceof Request;'],
    'a catch' => ['        try { $x = 1; } catch (Request $e) { $x = 2; }'],
]);

it('resolves a class named by a group import and by an aliased group member', function (): void {
    $source = "<?php\n\nnamespace Probe\\Grammar;\n\nuse Illuminate\\Http\\{Request, Response as Res};\n\nfinal class Grouped\n{\n    public function run(): void\n    {\n        \$a = Request::class;\n        \$b = Res::class;\n    }\n}\n";

    expect(phpReferencedClasses($source))
        ->toContain('Illuminate\Http\Request')
        ->toContain('Illuminate\Http\Response');
});

it('hears a method call whatever the receiver is', function (string $call): void {
    $source = "<?php\n\nnamespace Probe\\Grammar;\n\nfinal class Caller\n{\n    public function run(): void\n    {\n        ".$call."\n    }\n}\n";

    expect(phpCalledMethods($source))->toContain('mapThrow');
})->with([
    'a static call' => ['\Probe\Ignored::mapThrow($e);'],
    'a static call on an imported short name' => ['Ignored::mapThrow($e);'],
    'an instance call' => ['$context->mapThrow($e);'],
    'a nullsafe call' => ['$context?->mapThrow($e);'],
    'a call two hops deep' => ['$this->reader->mapThrow($e);'],
    'a call on a fresh instance' => ['(new Reader)->mapThrow($e);'],
    'a static call through self' => ['self::mapThrow($e);'],
]);

it('names the class behind a static call however the class is written', function (string $call): void {
    $source = "<?php\n\nnamespace Probe\\Grammar;\n\nuse Illuminate\\Http\\Request;\nuse Illuminate\\Http\\Request as Req;\n\nfinal class Static_\n{\n    public function run(): void\n    {\n        ".$call."\n    }\n}\n";

    expect(phpStaticCalls($source))->toContain('Illuminate\Http\Request::capture');
})->with([
    'an imported short name' => ['$x = Request::capture();'],
    'an aliased import' => ['$x = Req::capture();'],
    'fully qualified inline' => ['$x = \Illuminate\Http\Request::capture();'],
]);

it('hears no method that is only named, not called', function (): void {
    $source = "<?php\n\nfinal class Quiet\n{\n    public function mapThrow(): void {}\n\n    public function run(): void\n    {\n        \$name = 'mapThrow';\n        \$this->other();\n    }\n}\n";

    expect(phpCalledMethods($source))->toBe(['other']);
});

it('reads a prefixed literal whatever quotes it is written in', function (): void {
    // A pattern anchored on `'…'` is blind to the same literal in double quotes, which is how a
    // hand-maintained catalogue goes short with the whole suite green.
    $source = <<<'PHP'
    <?php

    $a = 'parameter.removed';
    $b = "parameter.added-required";
    $c = "parameter.renamed-2";
    $d = 'parameter.with\'quote';
    $e = 'response.removed';
    $f = $x->parameter->removed;
    PHP;

    expect(phpStringLiterals($source, 'parameter.'))->toBe([
        'parameter.added-required',
        'parameter.removed',
        'parameter.renamed-2',
        "parameter.with'quote",
    ]);
});

it('reads every literal when no prefix is asked for, and none when the prefix matches nothing', function (): void {
    expect(phpStringLiterals("<?php\n\$a = 'one';\n\$b = 'two';\n"))->toBe(['one', 'two'])
        ->and(phpStringLiterals("<?php\n\$a = 'one';\n", 'zzz.'))->toBe([]);
});

it('names a declared type however the declaration is spelled, class or not', function (string $declaration, string $expected): void {
    expect(phpDeclaredTypes("<?php\n\nnamespace Probe\\Grammar;\n\n".$declaration))->toBe([$expected]);
})->with([
    'a plain class' => ['class Plain {}', 'Probe\Grammar\Plain'],
    'an attributed final class' => ["#[\\Attribute]\nfinal class Att {}", 'Probe\Grammar\Att'],
    'a declaration split over lines' => ["final\nclass Split {}", 'Probe\Grammar\Split'],
    'an interface' => ['interface Contract {}', 'Probe\Grammar\Contract'],
    'a trait' => ['trait Shared {}', 'Probe\Grammar\Shared'],
    'a backed enum' => ["enum Colour: string { case Red = 'red'; }", 'Probe\Grammar\Colour'],
    'a readonly class' => ['readonly class Ro {}', 'Probe\Grammar\Ro'],
]);

it('names a type declared inside a braced namespace', function (): void {
    expect(phpDeclaredTypes("<?php\n\nnamespace Probe\\Braced {\n    final class Inside {}\n}\n"))
        ->toBe(['Probe\Braced\Inside']);
});

it('hears a throw however the thrown thing is built', function (string $statement, bool $throws): void {
    $source = "<?php\n\nfinal class Raiser\n{\n    public function run(): void\n    {\n        ".$statement."\n    }\n}\n";

    expect(phpRaisesThrow($source))->toBe($throws);
})->with([
    'a new' => ['throw new \RuntimeException;', true],
    'a variable' => ['throw $e;', true],
    'a static factory' => ['throw \Probe\Conflict::for(1);', true],
    'late static binding' => ['throw static::make();', true],
    'a parenthesised new' => ['throw (new \RuntimeException);', true],
    'the expression form' => ['$x = $y ?? throw new \RuntimeException;', true],
    'a match arm' => ['$x = match (true) { default => throw new \RuntimeException };', true],
    'nothing thrown at all' => ["\$x = 'throw new Nope';", false],
]);

it('counts a call once per site, whatever surrounds it', function (): void {
    $source = "<?php\n\nfinal class Branchy\n{\n    public function run(): void\n    {\n"
        ."        \$a = \$this->resolvePolicy(1);\n"
        ."        \$b = \$this?->resolvePolicy(2);\n"
        ."        \$c = \$this\n            ->resolvePolicy(3);\n"
        ."        \$d = self::resolvePolicy(4);\n"
        ."        \$e = \$this->other();\n    }\n}\n";

    expect(phpMethodCallCount($source, 'resolvePolicy'))->toBe(4)
        ->and(phpMethodCallCount($source, 'other'))->toBe(1)
        ->and(phpMethodCallCount($source, 'neverCalled'))->toBe(0);
});

it('names the class handed to a call as a ::class argument, alias and all', function (): void {
    $source = "<?php\n\nnamespace Probe;\n\nuse Illuminate\\Http\\Request;\nuse Illuminate\\Http\\Response as Res;\n\n"
        ."final class Reader\n{\n    public function run(\\ReflectionClass \$r): void\n    {\n"
        ."        \$r->getAttributes(Request::class);\n"
        ."        \$r->getAttributes(name: Res::class);\n"
        ."        \$r->getAttributes(\\Illuminate\\Http\\JsonResponse::class);\n"
        ."        \$r->getProperties(\\Illuminate\\Http\\RedirectResponse::class);\n    }\n}\n";

    expect(phpClassConstArguments($source, 'getAttributes'))->toBe([
        'Illuminate\Http\JsonResponse',
        'Illuminate\Http\Request',
        'Illuminate\Http\Response',
    ])
        // …and a different call's argument is not swept in with them.
        ->and(phpClassConstArguments($source, 'getProperties'))->toBe(['Illuminate\Http\RedirectResponse']);
});

it('reads a match arm in either quote style, and never the default arm', function (): void {
    $source = "<?php\n\nfinal class Table\n{\n    public function pick(string \$keyword): int\n    {\n"
        ."        return match (\$keyword) {\n"
        ."            'date' => 1,\n"
        ."            \"semver\" => 2,\n"
        ."            'a', 'b' => 3,\n"
        ."            default => 0,\n"
        ."        };\n    }\n}\n";

    expect(phpMatchArmLiterals($source, 'keyword'))->toBe(['a', 'b', 'date', 'semver'])
        // A match on some other subject is not this table.
        ->and(phpMatchArmLiterals($source, 'other'))->toBe([]);
});

it('reads a stub placeholder that is not all lowercase', function (): void {
    expect(stubPlaceholderNames('{{ version }} {{ className }} {{ FIELD_2 }} {{ a.b }} {{  spaced  }}'))
        ->toBe(['FIELD_2', 'a.b', 'className', 'spaced', 'version']);
});

it('answers null for a source it cannot parse, rather than an empty set that reads as agreement', function (): void {
    expect(phpParsedSource('<?php final class Broken { public function'))->toBeNull()
        ->and(phpDeclaredClasses('<?php final class Broken { public function'))->toBe([])
        // …and the plain case really does parse, so the row above is about the syntax error and not
        // about a parser that answers null for everything.
        ->and(phpParsedSource('<?php final class Fine {}'))->not->toBeNull();
});
