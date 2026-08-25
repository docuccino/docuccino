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
 *     action and its controller chain, so anything read back out of an `AttributeSet` is reached on a
 *     class, a method or a function.
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
            foreach (['CLASS', 'METHOD', 'FUNCTION'] as $surface) {
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
 * whatever it writes out in full.
 *
 * @return array<string, string>
 */
function attribute_reader_names(string $source): array
{
    $names = [];

    preg_match_all('/^use\s+Docuccino\\\\Attributes\\\\(\w+)(?:\s+as\s+(\w+))?\s*;/m', $source, $imports, PREG_SET_ORDER);
    foreach ($imports as $import) {
        $names[($import[2] ?? '') !== '' ? $import[2] : $import[1]] = $import[1];
    }

    preg_match_all('/\\\\?Docuccino\\\\+Attributes\\\\+(\w+)::class/', $source, $qualified);
    foreach ($qualified[1] as $name) {
        $names[$name] = $name;
    }

    return $names;
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
