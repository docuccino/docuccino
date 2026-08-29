<?php

declare(strict_types=1);

/*
 * Which reflection surfaces the packages actually read each Docuccino attribute off.
 *
 * A published attribute declares the places it may be written; nothing until this checked that any of
 * those places is read, and four surfaces shipped doing nothing at all. The answer has to come from
 * the source rather than from a list beside it, so this scans for the two ways an attribute is ever
 * reached:
 *
 *   - REFLECTION. A file that calls `getAttributes(` and names an attribute class reads that
 *     attribute off whichever reflection surfaces the file works with. Surfaces are read off the
 *     `Reflection*` vocabulary the file uses, per file rather than per call — a grep-level
 *     approximation, deliberately: typing a receiver variable properly means dataflow, and the
 *     coarse answer is exact on every reader the packages have. It can only over-claim inside a file
 *     that already reads that attribute, never claim a file that reads nothing.
 *   - THE BAG. `AttributeCollector` materialises every `Docuccino\Attributes\*` declared on a route's
 *     action and its controller chain, so anything read back out of an `AttributeSet` is reached on
 *     whatever surfaces that collector reflects over — read off ITS source
 *     ({@see attribute_collector_surfaces()}) rather than restated here. The trio it comes to today was
 *     hard-coded, which made the credit true by assertion: narrowing the collector to classes alone left
 *     every attribute documented as usable on a closure route claiming a reader it no longer had.
 *
 * What stays coarse is which CALL SITE's bag an attribute came out of. `collectOne()` is handed one
 * reflection and `collect()` walks an action plus its controller chain, and crediting a read with the
 * collector's whole surface over-claims for the former. Telling them apart means dataflow; the coarse
 * answer is exact on every reader the packages have, and it can only over-claim inside a file that
 * already reads that attribute.
 */

/**
 * The reflection surfaces each attribute is read on, `ShortName => sorted list of target names`.
 * Attributes nothing reads are absent rather than empty.
 *
 * @param  list<string>  $directories
 * @return array<string, list<string>>
 */
function attribute_target_readers(array $directories): array
{
    $bagSurfaces = attribute_collector_surfaces($directories);

    $found = [];
    foreach (attribute_reader_files($directories) as $file) {
        $source = (string) file_get_contents($file);
        $names = attribute_reader_names($source);

        foreach (attribute_reader_reflected($source, $names) as $name => $surfaces) {
            foreach ($surfaces as $surface) {
                $found[$name][$surface] = true;
            }
        }

        foreach (attribute_reader_bag_reads($source, $names) as $name) {
            foreach (attribute_bag_surfaces($source, $bagSurfaces) as $surface) {
                $found[$name][$surface] = true;
            }
        }
    }

    $out = [];
    foreach ($found as $name => $surfaces) {
        $list = array_keys($surfaces);
        sort($list);
        $out[$name] = $list;
    }
    ksort($out);

    return $out;
}

/**
 * The surfaces a bag read in THIS file earns.
 *
 * The collector has two entry points and they do not reach the same places. `collect()` is handed a
 * route action and walks its controller chain, so a bag from the pipeline carries every surface the
 * collector reflects over. `collectOne()` is handed ONE reflection and walks nowhere, so a file that
 * builds its own bag that way reaches only what it reflects over — `#[Webhook]` is read out of a bag
 * built from a `ReflectionClass`, and crediting it with the whole trio sanctioned two dead surfaces:
 * declaring it on a method or a function passed, and nothing would ever read it.
 *
 * Still per file rather than per call, for the reason the reflection half is. A file that calls
 * `collectOne()` and names a wide vocabulary over-claims exactly as before.
 *
 * @param  list<string>  $collectorSurfaces
 * @return list<string>
 */
function attribute_bag_surfaces(string $source, array $collectorSurfaces): array
{
    if (! str_contains($source, 'collectOne(')) {
        return $collectorSurfaces;
    }

    $own = attribute_reader_surfaces($source);

    // A file with no reflection vocabulary of its own tells us nothing, so it keeps the wider credit
    // rather than losing every cell to a scan that found nothing.
    return $own === [] ? $collectorSurfaces : $own;
}

/**
 * The reflection surfaces the `AttributeSet` collector materialises from, read off its own source with
 * the same vocabulary the reflection half uses — so the credit a bag read earns is a fact about the
 * collector rather than a trio written down beside it.
 *
 * A missing or unreadable collector throws rather than falling back: a credit derived from nothing would
 * hand every bag-read attribute an empty surface list and fail every cell at once, which reads as a
 * broken guard instead of a missing one.
 *
 * @param  list<string>  $directories
 * @return list<string>
 */
function attribute_collector_surfaces(array $directories): array
{
    foreach (attribute_reader_files($directories) as $file) {
        if (basename($file) !== 'AttributeCollector.php') {
            continue;
        }

        $surfaces = attribute_reader_surfaces((string) file_get_contents($file));

        if ($surfaces === []) {
            throw new RuntimeException($file.' names no reflection surface; the bag credit would be empty.');
        }

        return $surfaces;
    }

    throw new RuntimeException('AttributeCollector.php was not found under any reader directory.');
}

/**
 * Every PHP source file under the given directories.
 *
 * @param  list<string>  $directories
 * @return list<string>
 */
function attribute_reader_files(array $directories): array
{
    $files = [];
    foreach ($directories as $directory) {
        if (! is_dir($directory)) {
            continue;
        }

        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS));
        foreach ($iterator as $entry) {
            if ($entry instanceof SplFileInfo && $entry->isFile() && $entry->getExtension() === 'php') {
                $files[] = $entry->getPathname();
            }
        }
    }
    sort($files);

    return $files;
}

/**
 * The Docuccino attributes a file can name, as `symbol => ShortName` — imports (aliases included) plus
 * whatever it writes out in full. Sub-namespaces are read too: an attribute under
 * `Docuccino\Attributes\Versioning\` is imported and then written short, so a pattern stopping at one
 * segment would credit its readers to nothing at all.
 *
 * @return array<string, string>
 */
function attribute_reader_names(string $source): array
{
    $names = [];

    preg_match_all('/^use\s+Docuccino\\\\Attributes\\\\((?:\w+\\\\)*\w+)(?:\s+as\s+(\w+))?\s*;/m', $source, $imports, PREG_SET_ORDER);
    foreach ($imports as $import) {
        $short = attribute_reader_short_name($import[1]);
        $names[($import[2] ?? '') !== '' ? $import[2] : $short] = $short;
    }

    preg_match_all('/\\\\?Docuccino\\\\+Attributes\\\\+((?:\w+\\\\+)*\w+)::class/', $source, $qualified);
    foreach ($qualified[1] as $name) {
        $short = attribute_reader_short_name($name);
        $names[$short] = $short;
    }

    return $names;
}

/**
 * The name a reader writes, which is the last segment — the tables this feeds are keyed the same way,
 * and so is the reference page.
 */
function attribute_reader_short_name(string $name): string
{
    $name = str_replace('\\\\', '\\', $name);
    $separator = strrpos($name, '\\');

    return $separator === false ? $name : substr($name, $separator + 1);
}

/**
 * The surfaces a file that reads attributes off reflection reads the ones it names on.
 *
 * @param  array<string, string>  $names
 * @return array<string, list<string>>
 */
function attribute_reader_reflected(string $source, array $names): array
{
    if ($names === [] || ! str_contains($source, 'getAttributes(')) {
        return [];
    }

    $surfaces = attribute_reader_surfaces($source);
    if ($surfaces === []) {
        return [];
    }

    $out = [];
    foreach ($names as $symbol => $name) {
        if (preg_match('/\b'.preg_quote($symbol, '/').'\b\s*::\s*class/', $source) === 1) {
            $out[$name] = $surfaces;
        }
    }

    return $out;
}

/**
 * The reflection surfaces a file works with, read off the `Reflection*` classes and the walk methods
 * it names. A file naming none reads no attributes off reflection.
 *
 * @return list<string>
 */
function attribute_reader_surfaces(string $source): array
{
    $vocabulary = [
        'CLASS' => ['ReflectionClass', 'ReflectionObject', 'ReflectionEnum'],
        'PROPERTY' => ['ReflectionProperty', 'getProperties(', 'getProperty('],
        'METHOD' => ['ReflectionMethod', 'ReflectionFunctionAbstract', 'getMethods(', 'getMethod('],
        'FUNCTION' => ['ReflectionFunction', 'ReflectionFunctionAbstract'],
        'PARAMETER' => ['ReflectionParameter', 'getParameters('],
        'CLASS_CONSTANT' => ['ReflectionClassConstant', 'ReflectionEnumUnitCase', 'ReflectionEnumBackedCase', 'getCases(', 'getReflectionConstants('],
    ];

    $found = [];
    foreach ($vocabulary as $surface => $needles) {
        foreach ($needles as $needle) {
            if (str_contains($source, $needle)) {
                $found[] = $surface;

                break;
            }
        }
    }

    return $found;
}

/**
 * The attributes a file reads back out of an `AttributeSet` — the collector's bag.
 *
 * @param  array<string, string>  $names
 * @return list<string>
 */
function attribute_reader_bag_reads(string $source, array $names): array
{
    preg_match_all('/attributes(?:\(\))?\s*->\s*(?:all|first|has)\(\s*(\w+)\s*::\s*class\s*\)/', $source, $matches);

    $out = [];
    foreach ($matches[1] as $symbol) {
        if (isset($names[$symbol])) {
            $out[$names[$symbol]] = true;
        }
    }

    return array_keys($out);
}
