<?php

declare(strict_types=1);

/*
 * Embeds each published UIR schema's siblings into it, then copies every published file from its
 * authoring home in spec/uir/<version>/ to the copy php/core ships, so `Validator` resolves one out of
 * a vendor/ install rather than over the network.
 *
 * Every version AND every file in it, rather than the newest or a known filename: a published `$id` is
 * served forever, and the family is two files from 2.0 on — the document schema and the extension
 * schema it embeds. A tool that copied only the name it was written against would leave the second
 * one stale in silence. `SchemaShippingTest` reads the same directories and holds the two sets equal,
 * byte for byte.
 *
 * The embedding is why this runs before the copy. A published schema has to validate an instance with
 * nothing beside it: a user vendors one file, and a reference by absolute `$id` then costs them an
 * outbound fetch on every CI run, or an unresolved-reference failure where the tool declines to make
 * one. Draft 2020-12 answers that with an embedded schema resource — the sibling, verbatim, under a
 * `$defs` member, carrying its own `$id` — so the reference resolves inside the file it was written in
 * while the sibling goes on being published standalone for a third party to apply to any OpenAPI
 * document. `SchemaSelfContainmentTest` holds both halves: no reference leaves the file, and the
 * embedded copy equals the standalone one.
 *
 * Generated rather than hand-kept, for the reason a second copy always is. The splice is TEXTUAL —
 * the sibling's own bytes, indented — because a decode/re-encode round trip does not preserve this
 * family: an empty `{}` decoded to a PHP array comes back as `[]`, which is a different schema.
 *
 * A schema file is a `.json` file, spelled that way here, in `website/scripts/sync-schema.mjs` and in
 * that guard. The three used to disagree — this one took whatever `is_file()` said yes to — and the
 * disagreement was not cosmetic: a `.DS_Store` or an editor backup left beside a schema was copied
 * into `php/core/resources/` and SHIPPED inside the composer package, where a guard enumerating
 * `.json` could not see it. The copy also ran one way only, so a file deleted from `spec/` kept
 * shipping for ever with the suite green. Both are handled below.
 *
 *   composer sync-schema         # embed, then copy spec/ -> php/core/resources/ (run to refresh)
 *   composer sync-schema:check   # fail (exit 1) if either half is not what a run would write
 *
 * `--check` is what CI runs, because the file this writes the embedded copy back into is also the
 * file a maintainer edits: a `$defs` sorted alphabetically or run through a formatter is an ordinary
 * edit that leaves the generated block no longer the generator's own answer. The guard lives in the
 * workflow that always runs, for the reason `website/scripts/sync-schema.mjs` gives for its own — a
 * guard that only runs where the site is built is one a standalone deploy skips.
 */

$root = dirname(__DIR__);
$source = $root.'/spec/uir';
$destination = $root.'/php/core/resources/spec/uir';

// Anything but `--check` is a typo, and a typo tolerated inside a guard job would run the WRITING
// form and report green for having run at all.
$check = false;
foreach (array_slice($argv, 1) as $argument) {
    if ($argument !== '--check') {
        fwrite(STDERR, 'Unknown argument '.$argument.". Usage: sync-schema.php [--check]\n");

        exit(1);
    }

    $check = true;
}

/**
 * The `<version>/<file>` pairs under a uir root, sorted, so both sides are read the same way.
 *
 * @return list<string>
 */
$schemaFiles = static function (string $directory): array {
    if (! is_dir($directory)) {
        return [];
    }

    $versions = array_values(array_filter(
        scandir($directory) ?: [],
        static fn (string $entry): bool => $entry !== '.' && $entry !== '..' && is_dir($directory.'/'.$entry),
    ));
    sort($versions);

    $found = [];
    foreach ($versions as $version) {
        $names = array_values(array_filter(
            scandir($directory.'/'.$version) ?: [],
            static fn (string $entry): bool => str_ends_with($entry, '.json') && is_file($directory.'/'.$version.'/'.$entry),
        ));
        sort($names);

        foreach ($names as $name) {
            $found[] = $version.'/'.$name;
        }
    }

    return $found;
};

$canonical = $schemaFiles($source);

if ($canonical === []) {
    fwrite(STDERR, "No UIR schemas found under spec/uir.\n");

    exit(1);
}

$fail = static function (string $message): never {
    fwrite(STDERR, $message."\n");

    exit(1);
};

/**
 * Every absolute `$id` a decoded schema references, deduplicated, with the fragment dropped — one
 * embedding answers `…/extension.schema.json` and `…#/$defs/node` alike.
 *
 * @return list<string>
 */
$referencedIds = static function (mixed $node) use (&$referencedIds): array {
    $found = [];

    foreach ((array) $node as $key => $value) {
        if ($key === '$ref' && is_string($value) && str_contains($value, '://')) {
            $found[explode('#', $value)[0]] = true;
        } elseif (is_object($value) || is_array($value)) {
            foreach ($referencedIds($value) as $id) {
                $found[$id] = true;
            }
        }
    }

    return array_keys($found);
};

/**
 * The offset just past the JSON value that starts at $at, whatever kind it is. String-aware, so a
 * brace inside a description does not close an object.
 */
$valueEnd = static function (string $text, int $at) use ($fail): int {
    $n = strlen($text);
    $opening = $text[$at] ?? '';

    if ($opening === '{' || $opening === '[') {
        $depth = 0;
        $inString = false;
        $escaped = false;

        for ($i = $at; $i < $n; $i++) {
            $character = $text[$i];

            if ($inString) {
                if ($escaped) {
                    $escaped = false;
                } elseif ($character === '\\') {
                    $escaped = true;
                } elseif ($character === '"') {
                    $inString = false;
                }

                continue;
            }

            if ($character === '"') {
                $inString = true;
            } elseif ($character === '{' || $character === '[') {
                $depth++;
            } elseif (($character === '}' || $character === ']') && --$depth === 0) {
                return $i + 1;
            }
        }

        $fail('Unbalanced JSON value while splicing an embedded schema.');
    }

    if ($opening === '"') {
        for ($i = $at + 1; $i < $n; $i++) {
            if ($text[$i] === '\\') {
                $i++;
            } elseif ($text[$i] === '"') {
                return $i + 1;
            }
        }

        $fail('Unterminated JSON string while splicing an embedded schema.');
    }

    // A number, `true`, `false` or `null`: it runs to whatever separates it from the next member.
    for ($i = $at; $i < $n; $i++) {
        if (str_contains(",}] \t\r\n", $text[$i])) {
            return $i;
        }
    }

    $fail('Unterminated JSON value while splicing an embedded schema.');
};

/**
 * The offset just past the `{` of the ROOT object's `$defs` member. Found by depth rather than by
 * searching for the text, because an embedded resource brings a `$defs` of its own and a re-run would
 * otherwise splice into whichever came first.
 */
$rootDefsOpen = static function (string $text) use ($fail): int {
    $depth = 0;
    $key = null;

    for ($i = 0, $n = strlen($text); $i < $n; $i++) {
        $character = $text[$i];

        if ($character === '"') {
            $end = $i + 1;
            while ($end < $n && $text[$end] !== '"') {
                $end += $text[$end] === '\\' ? 2 : 1;
            }

            $key = $depth === 1 ? substr($text, $i + 1, $end - $i - 1) : null;
            $i = $end;

            continue;
        }

        if ($character === '{' || $character === '[') {
            if ($character === '{' && $depth === 1 && $key === '$defs') {
                return $i + 1;
            }

            $depth++;
        } elseif ($character === '}' || $character === ']') {
            $depth--;
        }
    }

    $fail('A schema embedding a sibling must declare a `$defs` object at its root.');
};

/**
 * Every direct member of the ROOT `$defs`, in file order, as the offsets a splice needs: `lead` is
 * where the whitespace before it begins, `start` its key quote, `end` just past its value, and `next`
 * just past the comma after it, if one follows.
 *
 * @return list<array{name: string, lead: int, start: int, end: int, next: int}>
 */
$rootDefsMembers = static function (string $text) use ($rootDefsOpen, $valueEnd, $fail): array {
    $n = strlen($text);
    $i = $rootDefsOpen($text);
    $members = [];

    $skipSpace = static function (int $from) use ($text, $n): int {
        while ($from < $n && ctype_space($text[$from])) {
            $from++;
        }

        return $from;
    };

    while (true) {
        $lead = $i;
        $i = $skipSpace($i);

        if (($text[$i] ?? '') === '}') {
            return $members;
        }

        if (($text[$i] ?? '') !== '"') {
            $fail('Could not read the root `$defs` of a schema: expected a member name at byte '.$i.'.');
        }

        $start = $i;
        $keyEnd = $i + 1;
        while ($keyEnd < $n && $text[$keyEnd] !== '"') {
            $keyEnd += $text[$keyEnd] === '\\' ? 2 : 1;
        }

        $i = $skipSpace($keyEnd + 1);

        if (($text[$i] ?? '') !== ':') {
            $fail('Could not read the root `$defs` of a schema: no `:` after the member at byte '.$start.'.');
        }

        $end = $valueEnd($text, $skipSpace($i + 1));
        $after = $skipSpace($end);
        $next = ($text[$after] ?? '') === ',' ? $after + 1 : $end;

        $members[] = [
            'name' => substr($text, $start + 1, $keyEnd - $start - 1),
            'lead' => $lead,
            'start' => $start,
            'end' => $end,
            'next' => $next,
        ];

        $i = $next;
    }
};

/**
 * $sibling's own bytes spliced in as the first member of $text's root `$defs`, replacing whatever is
 * already there under that name.
 *
 * STRUCTURAL rather than anchored on the bytes a previous run wrote: the member is found wherever it
 * sits in `$defs` and at whatever indent, so a re-run writes the same bytes for any file the walk can
 * read — not only for one this tool wrote last. Anchoring is how the replacement misses and the
 * prepend fires anyway, and a second member under one name is undefined behaviour that every decoder
 * resolves its own way. Hence the count afterwards: exactly one, or nothing is written at all.
 */
$embed = static function (string $text, string $key, string $sibling) use ($rootDefsMembers, $rootDefsOpen, $fail): string {
    $members = $rootDefsMembers($text);

    // From the back, so the offsets ahead of each removal stay valid. Every match goes, so a file
    // that somehow already carries two is healed rather than grown.
    foreach (array_reverse(array_keys($members)) as $index) {
        if ($members[$index]['name'] !== $key) {
            continue;
        }

        // A member with no comma after it is the last one, and its separator is the comma BEFORE it.
        $from = $members[$index]['next'] === $members[$index]['end'] && $index > 0
            ? $members[$index - 1]['end']
            : $members[$index]['lead'];

        $text = substr($text, 0, $from).substr($text, $members[$index]['next']);
    }

    $open = $rootDefsOpen($text);
    $remaining = $rootDefsMembers($text);

    // The file's own layout: the whitespace before the member that will follow this one, or — where
    // `$defs` is empty and there is none to read — a line indented two past the `$defs` key itself.
    if ($remaining !== []) {
        $lead = substr($text, $remaining[0]['lead'], $remaining[0]['start'] - $remaining[0]['lead']);
    } else {
        $lineStart = (int) strrpos(substr($text, 0, $open), "\n") + 1;
        $lead = "\n".str_repeat(' ', strspn($text, ' ', $lineStart) + 2);
    }

    $indent = (string) preg_replace('/^.*\n/s', '', $lead);

    // Every line but the first moves in by the member's own indent; the first follows `"key": `.
    $body = str_replace("\n", "\n".$indent, rtrim($sibling, "\n"));

    $text = substr($text, 0, $open).$lead.'"'.$key.'": '.$body.($remaining === [] ? '' : ',').substr($text, $open);

    $landed = array_filter($rootDefsMembers($text), static fn (array $member): bool => $member['name'] === $key);

    if (count($landed) !== 1) {
        $fail('Embedding left '.count($landed).' members at $defs/'.$key.'; a schema may declare it once.');
    }

    return $text;
};

/** json_decode, with a failure that names the file rather than arriving as a stack trace. */
$decode = static function (string $text, string $path, bool $associative) use ($fail): mixed {
    try {
        return json_decode($text, $associative, flags: JSON_THROW_ON_ERROR);
    } catch (JsonException $exception) {
        $fail($path.' is not valid JSON: '.$exception->getMessage().'.');
    }
};

$drift = false;

/*
 * Embed before copying, so the three copy roots carry the finished file and the drift guard compares
 * what a user would actually vendor. `$finished` is what a run WOULD write, which is what `--check`
 * compares every copy against — reading spec/ back off disk in check mode would compare the copies
 * with an authoring file the tool has just declined to bring up to date.
 */
$finished = [];

foreach (array_unique(array_map(static fn (string $name): string => explode('/', $name)[0], $canonical)) as $version) {
    $names = array_map(
        static fn (string $name): string => explode('/', $name)[1],
        array_filter($canonical, static fn (string $name): bool => str_starts_with($name, $version.'/')),
    );

    $text = [];
    $declared = [];
    foreach ($names as $name) {
        $path = $source.'/'.$version.'/'.$name;
        $text[$name] = (string) file_get_contents($path);
        $decoded = $decode($text[$name], 'spec/uir/'.$version.'/'.$name, false);

        if (! is_object($decoded) || ! is_string($decoded->{'$id'} ?? null)) {
            $fail('spec/uir/'.$version.'/'.$name.' declares no $id.');
        }

        $declared[$decoded->{'$id'}] = $name;
    }

    foreach ($names as $name) {
        $path = $source.'/'.$version.'/'.$name;
        $decoded = $decode($text[$name], 'spec/uir/'.$version.'/'.$name, false);
        $written = $text[$name];

        foreach ($referencedIds($decoded) as $id) {
            if (($declared[$id] ?? $name) === $name) {
                // A reference to itself needs no embedding; anything else is outside the family and
                // cannot be made self-contained by copying a file this directory does not hold.
                if (! isset($declared[$id])) {
                    $fail('spec/uir/'.$version.'/'.$name.' references '.$id.', which no file of this version declares.');
                }

                continue;
            }

            // `extension.schema.json` → `extension`: the member name is the sibling's own.
            $key = (string) preg_replace('/\.schema$/', '', basename($declared[$id], '.json'));

            $written = $embed($written, $key, $text[$declared[$id]]);

            // Decoded rather than trusted: the splice is textual, so "it landed" is a thing to read
            // back off the result, and a decode that fails here is a splice that broke the file.
            $spliced = $decode($written, 'spec/uir/'.$version.'/'.$name.' (after embedding '.$declared[$id].')', true);

            if (! is_array($spliced) || ($spliced['$defs'][$key]['$id'] ?? null) !== $id) {
                $fail('Embedding '.$declared[$id].' into '.$name.' did not land at $defs/'.$key.'.');
            }
        }

        $finished[$version.'/'.$name] = $written;

        if ($written === $text[$name]) {
            continue;
        }

        if ($check) {
            fwrite(STDERR, 'spec/uir/'.$version.'/'.$name." is not what the embedding would write — run composer sync-schema.\n");
            $drift = true;

            continue;
        }

        if (file_put_contents($path, $written) === false) {
            $fail('Could not write '.$path.'.');
        }

        echo 'Embedded the referenced schemas of spec/uir/'.$version.'/'.$name.' into it.'.PHP_EOL;
    }
}

// A file the package ships that spec/ no longer authors keeps a schema alive that nobody maintains,
// and it ships inside the release. Removed rather than left, the same as the website copy does.
foreach (array_diff($schemaFiles($destination), $canonical) as $orphan) {
    if ($check) {
        fwrite(STDERR, 'php/core/resources/spec/uir/'.$orphan." is shipped but spec/ no longer publishes it — run composer sync-schema.\n");
        $drift = true;

        continue;
    }

    if (! unlink($destination.'/'.$orphan)) {
        fwrite(STDERR, "Could not remove {$destination}/{$orphan}.\n");

        exit(1);
    }

    echo 'Removed php/core/resources/spec/uir/'.$orphan.' (no longer published by spec/).'.PHP_EOL;
}

foreach ($canonical as $name) {
    $to = $destination.'/'.$name;

    if ($check) {
        if (! is_file($to) || file_get_contents($to) !== $finished[$name]) {
            fwrite(STDERR, 'php/core/resources/spec/uir/'.$name." has drifted from spec/uir/{$name} — run composer sync-schema.\n");
            $drift = true;
        } else {
            echo 'UIR schema '.$name.': in sync.'.PHP_EOL;
        }

        continue;
    }

    @mkdir(dirname($to), 0755, true);

    if (file_put_contents($to, $finished[$name]) === false) {
        fwrite(STDERR, "Could not write {$to}.\n");

        exit(1);
    }

    echo 'Synced spec/uir/'.$name.' -> php/core/resources/spec/uir/'.$name.PHP_EOL;
}

if ($drift) {
    exit(1);
}
