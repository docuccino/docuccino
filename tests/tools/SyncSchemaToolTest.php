<?php

declare(strict_types=1);

/*
 * `tools/sync-schema.php` against the authoring file it writes back into.
 *
 * The embedded `$defs/extension` block is GENERATED, and it lives in `spec/uir/2.0/schema.json`,
 * which is also the file a maintainer opens. So an ordinary edit — a `$defs` sorted alphabetically, a
 * reformat, a checkout with CRLF line endings, a new member added ahead of it — arrives at the tool
 * as a file whose generated block is no longer where it was written. A splice anchored on the exact
 * bytes of a previous run misses in all four cases and prepends a SECOND member under one name, which
 * is undefined behaviour in RFC 8259: PHP and Python read the last, some decoders read the first,
 * and every guard over the family stays green because all three copy roots sync from the same
 * duplicated source.
 *
 * Stated from the contract rather than from the tool: a JSON object declares a member once. The count
 * below is read off the written bytes with a scanner of this file's own, so a tool that came to
 * disagree with itself cannot talk this guard round.
 *
 * The runs happen in a throwaway copy of the repository's `tools/`, `spec/uir/` and
 * `php/core/resources/spec/uir/`. The tool resolves everything from its own directory, so a copy of
 * the script IS the script under test, and the tracked files are never written to.
 */

/**
 * A sandbox holding the script, the authoring schemas and the package copy — the whole of what a run
 * reads and writes.
 */
function syncSchemaSandbox(string $label): string
{
    $repository = dirname(__DIR__, 2);
    $root = sys_get_temp_dir().'/docuccino-sync-schema-'.$label.'-'.uniqid();

    mkdir($root.'/tools', recursive: true);
    copy($repository.'/tools/sync-schema.php', $root.'/tools/sync-schema.php');

    foreach ([$repository.'/spec/uir' => $root.'/spec/uir', $repository.'/php/core/resources/spec/uir' => $root.'/php/core/resources/spec/uir'] as $from => $to) {
        foreach (schemaFilesUnder($from) as $name => $_) {
            @mkdir(dirname($to.'/'.$name), recursive: true);
            copy($from.'/'.$name, $to.'/'.$name);
        }
    }

    return $root;
}

/**
 * Run the sandboxed script, both streams captured.
 *
 * @return array{code: int, out: string}
 */
function syncSchemaRun(string $root, string $arguments = ''): array
{
    $command = escapeshellarg(PHP_BINARY).' '.escapeshellarg($root.'/tools/sync-schema.php').' '.$arguments.' 2>&1';

    exec($command, $lines, $code);

    return ['code' => $code, 'out' => implode("\n", $lines)];
}

/**
 * How many times $key is declared as a member of the ROOT `$defs` object, counted over the raw bytes.
 *
 * `json_decode` cannot answer this — it keeps the last of a repeated key and reports one — and
 * neither can the tool's own walker without the guard agreeing with whatever the tool did.
 */
function syncSchemaRootDefsCount(string $text, string $key): int
{
    $depth = 0;
    $defsDepth = null;
    $count = 0;
    $lastKey = null;

    for ($i = 0, $n = strlen($text); $i < $n; $i++) {
        $character = $text[$i];

        if ($character === '"') {
            $end = $i + 1;
            while ($end < $n && $text[$end] !== '"') {
                $end += $text[$end] === '\\' ? 2 : 1;
            }

            $name = substr($text, $i + 1, $end - $i - 1);
            $colon = $end + 1;
            while ($colon < $n && ctype_space($text[$colon])) {
                $colon++;
            }

            if (($text[$colon] ?? '') === ':') {
                if ($name === $key && $defsDepth !== null && $depth === $defsDepth) {
                    $count++;
                }

                $lastKey = $name;
            }

            $i = $end;

            continue;
        }

        if ($character === '{' || $character === '[') {
            if ($character === '{' && $depth === 1 && $lastKey === '$defs' && $defsDepth === null) {
                $defsDepth = $depth + 1;
            }

            $depth++;
        } elseif ($character === '}' || $character === ']') {
            $depth--;

            if ($defsDepth !== null && $depth < $defsDepth) {
                $defsDepth = null;
            }
        }
    }

    return $count;
}

/**
 * A schema's embedded member and everything else, as lines — the surgery two of the perturbations
 * need in order to put the block back somewhere else.
 *
 * @return array{list<string>, list<string>}
 */
function syncSchemaSplit(string $path): array
{
    $lines = explode("\n", (string) file_get_contents($path));
    $start = null;

    foreach ($lines as $index => $line) {
        if ($line === '    "extension": {') {
            $start = $index;
        } elseif ($start !== null && $line === '    },') {
            return [array_slice($lines, $start, $index - $start + 1), array_merge(array_slice($lines, 0, $start), array_slice($lines, $index + 1))];
        }
    }

    throw new RuntimeException('The embedded member is no longer spelled the way this fixture edits it.');
}

/** Apply one plausible edit to a file nothing marks as generated. */
function syncSchemaPerturb(string $which, string $path): void
{
    if ($which === 'crlf') {
        file_put_contents($path, str_replace("\n", "\r\n", (string) file_get_contents($path)));

        return;
    }

    if ($which === 'new member') {
        $text = (string) file_get_contents($path);
        file_put_contents($path, str_replace("\"\$defs\": {\n", "\"\$defs\": {\n    \"aardvark\": { \"type\": \"string\" },\n", $text));

        return;
    }

    [$block, $rest] = syncSchemaSplit($path);
    $at = (int) array_search('  "$defs": {', $rest, true);

    if ($which === 're-indented') {
        $block = array_map(static fn (string $line): string => (string) preg_replace('/^    /', '  ', $line), $block);
    } else {
        // Past the member that now sits first, so the block lands second.
        $depth = 0;

        for ($i = $at + 1, $n = count($rest); $i < $n; $i++) {
            $depth += substr_count($rest[$i], '{') - substr_count($rest[$i], '}');

            if ($depth <= 0) {
                $at = $i;
                break;
            }
        }
    }

    array_splice($rest, $at + 1, 0, $block);
    file_put_contents($path, implode("\n", $rest));
}

it('embeds a sibling exactly once, wherever the member sat and however it was spelled', function (string $which): void {
    $root = syncSchemaSandbox('perturbed');
    $path = $root.'/spec/uir/2.0/schema.json';
    $before = (string) file_get_contents($path);

    syncSchemaPerturb($which, $path);

    // The perturbation is real: a fixture that silently stopped editing the file would leave every
    // assertion below passing over an untouched copy.
    expect((string) file_get_contents($path))->not->toBe($before)
        ->and(syncSchemaRootDefsCount((string) file_get_contents($path), 'extension'))->toBe(1);

    // A generated block that has moved is drift, and the check form is what CI runs.
    $checked = syncSchemaRun($root, '--check');
    expect($checked['code'])->toBe(1)
        ->and($checked['out'])->toContain('spec/uir/2.0/schema.json is not what the embedding would write');

    $run = syncSchemaRun($root);
    $written = (string) file_get_contents($path);

    expect($run['code'])->toBe(0)
        ->and(syncSchemaRootDefsCount($written, 'extension'))->toBe(1)
        ->and(json_decode($written, true, flags: JSON_THROW_ON_ERROR))->toBeArray();

    // And it settles: the second run is the one whose bytes have to match the first, because that is
    // what `--check` asserts about a committed file on every push.
    expect(syncSchemaRun($root)['code'])->toBe(0)
        ->and((string) file_get_contents($path))->toBe($written)
        ->and(syncSchemaRun($root, '--check')['code'])->toBe(0);
})->with([
    'moved down the object' => ['moved'],
    're-indented' => ['re-indented'],
    'converted to CRLF' => ['crlf'],
    'a new member ahead of it' => ['new member'],
]);

it('heals a file that already carries the member twice', function (): void {
    // The state the anchored splice left behind, and the one every guard over the family called
    // green: two members under one name, which decoders disagree about.
    $root = syncSchemaSandbox('duplicated');
    $path = $root.'/spec/uir/2.0/schema.json';
    $text = (string) file_get_contents($path);
    $extension = (string) file_get_contents($root.'/spec/uir/2.0/extension.schema.json');

    // Prepended exactly as the anchored splice used to: the sibling's bytes, indented, as a second
    // first member. Offsets rather than a pattern, so the fixture cannot mis-escape its own subject.
    $opens = strpos($text, '"$defs": {');
    expect($opens)->not->toBeFalse();

    $at = (int) $opens + strlen('"$defs": {');
    $duplicate = "\n    \"extension\": ".str_replace("\n", "\n    ", rtrim($extension, "\n")).',';
    $spliced = substr($text, 0, $at).$duplicate.substr($text, $at);

    file_put_contents($path, $spliced);
    expect(json_decode($spliced, true, flags: JSON_THROW_ON_ERROR))->toBeArray();

    expect(syncSchemaRootDefsCount($spliced, 'extension'))->toBe(2)
        ->and(syncSchemaRun($root, '--check')['code'])->toBe(1);

    expect(syncSchemaRun($root)['code'])->toBe(0)
        ->and(syncSchemaRootDefsCount((string) file_get_contents($path), 'extension'))->toBe(1);
});

it('names the file when one of them is not JSON at all', function (): void {
    // A byte-order mark on a sibling used to arrive as an uncaught JsonException, whose message names
    // neither the file nor the directory — the same stray-file class the tool handles everywhere else.
    $root = syncSchemaSandbox('bom');
    $path = $root.'/spec/uir/2.0/extension.schema.json';

    file_put_contents($path, "\u{FEFF}".file_get_contents($path));

    foreach (['', '--check'] as $arguments) {
        $run = syncSchemaRun($root, $arguments);

        expect($run['code'])->toBe(1)
            ->and($run['out'])->toContain('spec/uir/2.0/extension.schema.json is not valid JSON')
            ->and($run['out'])->not->toContain('Stack trace');
    }
});

it('embeds into an empty $defs without writing a trailing comma', function (): void {
    // The degenerate shape: nothing to separate the new member from, so the comma the splice adds
    // between members has nothing to sit before. It used to make the file undecodable.
    $root = syncSchemaSandbox('empty-defs');
    $path = $root.'/spec/uir/2.0/schema.json';
    $text = (string) file_get_contents($path);

    file_put_contents($path, (string) preg_replace('/  "\$defs": \{.*\n\}\n$/s', "  \"\$defs\": {}\n}\n", $text));

    $run = syncSchemaRun($root);
    $written = (string) file_get_contents($path);

    expect($run['code'])->toBe(0)
        ->and(json_decode($written, true, flags: JSON_THROW_ON_ERROR))->toBeArray()
        ->and(syncSchemaRootDefsCount($written, 'extension'))->toBe(1);
});

it('writes nothing, and reports none, for the schemas as they are committed', function (): void {
    // The positive control for all of the above: the tracked corpus is already the tool's own answer,
    // so `--check` passes and a write run leaves every byte alone.
    $root = syncSchemaSandbox('unperturbed');
    $before = [];

    foreach (schemaFilesUnder($root.'/spec/uir') as $name => $_) {
        $before[$name] = (string) file_get_contents($root.'/spec/uir/'.$name);
    }

    // Anti-vacuity: the corpus really does carry an embedded member for the guard to be about.
    expect(count($before))->toBeGreaterThanOrEqual(4)
        ->and(syncSchemaRootDefsCount($before['2.0/schema.json'], 'extension'))->toBe(1)
        ->and(syncSchemaRun($root, '--check')['code'])->toBe(0)
        ->and(syncSchemaRun($root)['code'])->toBe(0);

    foreach ($before as $name => $text) {
        expect((string) file_get_contents($root.'/spec/uir/'.$name))->toBe($text, $name.' was rewritten by a run that had nothing to do');
    }
});

it('refuses an argument it does not know', function (): void {
    // A guard job that mistyped its flag would otherwise run the WRITING form and report green for
    // having run at all.
    $run = syncSchemaRun(syncSchemaSandbox('argument'), '--dry-run');

    expect($run['code'])->toBe(1)
        ->and($run['out'])->toContain('Unknown argument --dry-run');
});
