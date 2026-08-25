<?php

declare(strict_types=1);

/*
 * The guard behind the attribute reference's counts. The page says "all N attributes" in two places
 * and lists every one in an at-a-glance table, and a count nothing checks is a promise to remember —
 * the number that goes stale is always the one you didn't edit. This reads the shipped package
 * instead, so adding or removing an attribute fails here until both halves of the page agree.
 */
/** @return list<string> */
function shippedAttributeNames(): array
{
    $names = [];
    foreach (glob(dirname(__DIR__, 2).'/php/attributes/src/*.php') ?: [] as $file) {
        $names[] = basename($file, '.php');
    }
    sort($names);

    return $names;
}

function attributesReferencePage(): string
{
    return (string) file_get_contents(
        dirname(__DIR__, 2).'/website/src/content/docs/laravel/reference/attributes.md',
    );
}

/**
 * The attributes the page's at-a-glance table lists, by name. Rows only — a mention in prose is not a
 * catalogue entry, and the sections below each have a heading of their own.
 *
 * @return list<string>
 */
function referencedAttributeNames(string $page): array
{
    preg_match_all('/^\| \[`#\[(\w+)]`]\(#\w+\) \|/m', $page, $matches);

    $names = $matches[1];
    sort($names);

    return $names;
}

it('lists every attribute the package ships, and nothing it does not', function (): void {
    expect(referencedAttributeNames(attributesReferencePage()))->toBe(shippedAttributeNames());
});

it('states the count the package actually ships, everywhere it states one', function (): void {
    $expected = count(shippedAttributeNames());
    $page = attributesReferencePage();

    preg_match_all('/\b(\d+) attributes\b/', $page, $matches);

    expect($matches[1])->not->toBeEmpty()
        ->and(array_unique(array_map(intval(...), $matches[1])))->toBe([$expected]);
});

it('documents every constructor parameter every attribute takes', function (): void {
    // The page prints each attribute's constructor verbatim, and a signature nobody checks is a promise
    // to remember — a parameter added to a shipped attribute goes undocumented with the suite green.
    // Read off reflection rather than the page, and a new argument fails here until it is written down.
    $page = attributesReferencePage();

    $missing = [];
    $counted = 0;
    foreach (shippedAttributeNames() as $name) {
        /** @var class-string $class */
        $class = 'Docuccino\\Attributes\\'.$name;
        $section = attributeReferenceSection($page, $name);

        foreach ((new ReflectionClass($class))->getConstructor()?->getParameters() ?? [] as $parameter) {
            $counted++;
            if (! str_contains($section, '$'.$parameter->getName())) {
                $missing[] = $name.'::$'.$parameter->getName();
            }
        }
    }

    expect($counted)->toBeGreaterThan(40)
        ->and($missing)->toBe([]);
});

/** One attribute's reference section: from its heading to the next one, so a neighbour cannot cover for it. */
function attributeReferenceSection(string $page, string $name): string
{
    $start = strpos($page, '### `#['.$name.']`');
    if ($start === false) {
        return '';
    }

    $next = strpos($page, "\n### ", $start + 1);

    return $next === false ? substr($page, $start) : substr($page, $start, $next - $start);
}

/**
 * The `#[Attribute]` flag every target name the page may print stands for. A name outside this map is
 * a failure rather than a skip — a guard that quietly ignores prose it does not recognise is how the
 * prose drifts in the first place.
 *
 * @return array<string, int>
 */
function attributeTargetFlags(): array
{
    return [
        'CLASS' => Attribute::TARGET_CLASS,
        'METHOD' => Attribute::TARGET_METHOD,
        'FUNCTION' => Attribute::TARGET_FUNCTION,
        'PROPERTY' => Attribute::TARGET_PROPERTY,
        'PARAMETER' => Attribute::TARGET_PARAMETER,
        'CLASS_CONSTANT' => Attribute::TARGET_CLASS_CONSTANT,
    ];
}

/**
 * The sentence the page states an attribute's targets in: its own if it has one, otherwise the intro
 * of the `##` group it sits in — the five parameter attributes share one statement rather than
 * repeating it. Empty when neither says anything, which is itself a failure.
 */
function attributeTargetSentence(string $page, string $name): string
{
    foreach ([attributeReferenceSection($page, $name), attributeReferenceGroupIntro($page, $name)] as $text) {
        if (preg_match('/\b[Tt]argets?\b.*?\.(?=\s|$)/s', $text, $match) === 1) {
            return $match[0];
        }
    }

    return '';
}

/** The prose between an attribute's `##` group heading and the first `###` under it. */
function attributeReferenceGroupIntro(string $page, string $name): string
{
    $start = strpos($page, '### `#['.$name.']`');
    if ($start === false) {
        return '';
    }

    $group = strrpos(substr($page, 0, $start), "\n## ");
    if ($group === false) {
        return '';
    }

    $next = strpos($page, "\n### ", $group);

    return $next === false ? '' : substr($page, $group, $next - $group);
}

/**
 * The `#[Attribute]` flags one section's prose claims, or null when it claims none. The page writes
 * targets in backticks, either as one run (`CLASS | METHOD`) or one span each — so every backticked
 * span that looks like target constants is read, and one holding a name this vocabulary doesn't know
 * fails rather than being passed over. `repeatable` comes off the same sentence, negated first, so
 * "not repeatable" is not a claim that it is.
 */
function referencedAttributeFlags(string $page, string $name): ?int
{
    $sentence = attributeTargetSentence($page, $name);
    if ($sentence === '' || preg_match_all('/`([^`]+)`/', $sentence, $matches) === 0) {
        return null;
    }

    $known = attributeTargetFlags();
    $flags = 0;
    $named = 0;
    foreach ($matches[1] as $span) {
        $pieces = preg_split('/[|,\s]+/', trim($span), flags: PREG_SPLIT_NO_EMPTY) ?: [];

        // A span with no constant-shaped piece is ordinary prose — `#[Hidden]`, a config key. One that
        // has any is read whole, so `CLASS | THING` is a failure rather than a silent CLASS.
        if (array_filter($pieces, static fn (string $piece): bool => preg_match('/^[A-Z][A-Z_]*$/', $piece) === 1) === []) {
            continue;
        }

        foreach ($pieces as $piece) {
            if (! isset($known[$piece])) {
                return null;
            }
            $flags |= $known[$piece];
            $named++;
        }
    }

    if ($named === 0) {
        return null;
    }

    if (! str_contains($sentence, 'not repeatable') && str_contains($sentence, 'repeatable')) {
        $flags |= Attribute::IS_REPEATABLE;
    }

    return $flags;
}

it('states the targets and repeatability every attribute really declares', function (): void {
    // The page's "Targets `…`" line is the only place a reader learns where an attribute may be
    // written, and nothing read it before this: the flags and the prose could drift apart, and did.
    $page = attributesReferencePage();

    $wrong = [];
    $seen = 0;
    foreach (shippedAttributeNames() as $name) {
        /** @var class-string $class */
        $class = 'Docuccino\\Attributes\\'.$name;
        $declared = attributeFlagsOf($class);
        $documented = referencedAttributeFlags($page, $name);

        if ($documented === null) {
            $wrong[] = $name.': the page states no targets';

            continue;
        }

        $seen |= $documented;
        if ($documented !== $declared) {
            $wrong[] = sprintf('%s: page says %s, the attribute declares %s', $name, attributeFlagNames($documented), attributeFlagNames($declared));
        }
    }

    // Every target name the vocabulary knows is claimed somewhere, so a regex that stopped matching
    // one of them fails here rather than passing on a thinner page.
    expect($wrong)->toBe([])
        ->and(attributeFlagNames($seen))->toBe('CLASS | METHOD | FUNCTION | PROPERTY | PARAMETER | CLASS_CONSTANT | repeatable');
});

/** @param  class-string  $class */
function attributeFlagsOf(string $class): int
{
    $declarations = (new ReflectionClass($class))->getAttributes(Attribute::class);

    return $declarations === [] ? Attribute::TARGET_ALL : $declarations[0]->newInstance()->flags;
}

/** A bitmask spelled the way the page spells it, for a failure message that names the difference. */
function attributeFlagNames(int $flags): string
{
    $names = [];
    foreach (attributeTargetFlags() as $name => $flag) {
        if (($flags & $flag) === $flag) {
            $names[] = $name;
        }
    }

    if (($flags & Attribute::IS_REPEATABLE) === Attribute::IS_REPEATABLE) {
        $names[] = 'repeatable';
    }

    return $names === [] ? '(nothing)' : implode(' | ', $names);
}

it('gives every attribute a reference section of its own', function (): void {
    $page = attributesReferencePage();

    $missing = array_values(array_filter(
        shippedAttributeNames(),
        static fn (string $name): bool => ! str_contains($page, '### `#['.$name.']`'),
    ));

    expect($missing)->toBe([]);
});
