<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Config;

use Docuccino\Core\Config\ConfigFile;
use Docuccino\Core\Support\Arr;
use Symfony\Component\Yaml\Yaml;

/**
 * The settings surface: every key path `docuccino.yaml` may carry, read off the shipped file itself.
 *
 * There is no hand-maintained list of settings, and there is not going to be one. The shipped file is
 * already the declaration — `docuccino:install` writes it, the website's configuration reference is
 * held to it key for key, and it shows every option with the optional ones commented out. So the set
 * is derived from those bytes, comments included: a commented option is still an option, and a reader
 * that skipped them would report a key the file itself offers.
 *
 * A commented key is uncommented IN PLACE — the marker and the one space after it become nothing, and
 * the indentation on either side is untouched, which is what keeps a commented child nested under its
 * commented parent. Prose is dropped by being unable to look like YAML: the key pattern wants
 * lower-case with no spaces and a colon straight after, so a sentence that happens to carry a colon
 * ("Declares that this document IS an API version:") fails on both counts.
 *
 * @internal
 */
final class DeclaredSettings
{
    /**
     * Bags whose members the application NAMES. The segment below one of these is a name and not a
     * key, so it reads as `*` on both sides of any comparison.
     *
     * @var list<string>
     */
    public const array KEYED_MAPS = ['documents'];

    /**
     * The shipped file's paths, read once per process — the file cannot change under a build.
     *
     * @var list<string>|null
     */
    private static ?array $shipped = null;

    /** Where the file this package ships lives, which is also what `docuccino:install` copies. */
    public static function path(): string
    {
        return dirname(__DIR__, 2).'/config/'.ConfigFile::NAME;
    }

    /**
     * Every key path the shipped file declares, dotted, normalized and sorted.
     *
     * @return list<string>
     */
    public static function shipped(): array
    {
        return self::$shipped ??= self::of((string) @file_get_contents(self::path()));
    }

    /**
     * The key paths a settings file declares, live and commented-out alike, dotted and sorted.
     *
     * Accepts a whole file or a fragment of one, which is what `$base` is for: a fragment quoted under
     * a heading is read from that heading's path.
     *
     * @return list<string>
     */
    public static function of(string $yaml, string $base = ''): array
    {
        return self::normalized(self::paths(self::tree($yaml), $base));
    }

    /**
     * The shipped file's settings as a TREE — every option it declares, commented ones uncommented,
     * holding the value written beside it. The file shows every setting with its default, so this is
     * where a guard reads what a setting's documented default actually is rather than trusting a
     * constant to agree with the bytes an install writes.
     *
     * @return array<string, mixed>
     */
    public static function shippedTree(): array
    {
        return self::tree((string) @file_get_contents(self::path()));
    }

    /**
     * A settings file's declarations as a parsed tree, live and commented-out alike.
     *
     * @return array<string, mixed>
     */
    public static function tree(string $yaml): array
    {
        $kept = [];

        foreach (explode("\n", $yaml) as $line) {
            $marker = strpos($line, '#');

            if ($marker === false || trim($line) === '') {
                $kept[] = $line;

                continue;
            }

            if (trim(substr($line, 0, $marker)) !== '') {
                // A live setting with a trailing comment: YAML reads it correctly as it stands.
                $kept[] = $line;

                continue;
            }

            $content = substr($line, 0, $marker).preg_replace('/^# ?/', '', substr($line, $marker));

            if (preg_match('/^\s*(?:[A-Za-z_][A-Za-z0-9_.-]*:(?:\s|$)|- )/', $content) === 1) {
                $kept[] = $content;
            }
        }

        /** @var mixed $parsed */
        $parsed = Yaml::parse(implode("\n", self::reopened($kept)));

        return is_array($parsed) ? Arr::stringKeyed($parsed) : [];
    }

    /**
     * Application-chosen names collapse to `*`, so `documents.default.export` and `documents.*.export`
     * are the one key they both describe.
     *
     * @param  list<string>  $paths
     * @return list<string>
     */
    public static function normalized(array $paths): array
    {
        $normalized = [];

        foreach ($paths as $path) {
            $normalized[] = implode('.', self::wildcarded(explode('.', $path)));
        }

        $normalized = array_values(array_unique($normalized));
        sort($normalized);

        return $normalized;
    }

    /**
     * One path's segments with every application-chosen name replaced by `*`.
     *
     * @param  list<string>  $segments
     * @return list<string>
     */
    public static function wildcarded(array $segments): array
    {
        foreach (array_keys($segments) as $index) {
            if ($index > 0 && in_array($segments[$index - 1], self::KEYED_MAPS, true)) {
                $segments[$index] = '*';
            }
        }

        return $segments;
    }

    /**
     * The kept lines with a shipped empty collection re-opened where commented children follow it.
     *
     * `integrations: {}` beside a commented `api_resources:` bag is ONE key written two ways: the `{}`
     * is there because an empty value has to be spelled out — a blank one parses to null and hashes
     * differently — and the children are there because the file shows every option. Uncommenting them
     * would otherwise put a block under a value that is already closed, so the marker comes off and
     * the children nest where they were written to.
     *
     * @param  list<string>  $lines
     * @return list<string>
     */
    private static function reopened(array $lines): array
    {
        $indent = static fn (string $line): int => strlen($line) - strlen(ltrim($line, ' '));

        foreach ($lines as $index => $line) {
            if (preg_match('/^(\s*[A-Za-z_][A-Za-z0-9_.-]*:)\s*(?:\{\}|\[\])\s*(?:#.*)?$/', $line, $match) !== 1) {
                continue;
            }

            for ($next = $index + 1; $next < count($lines); $next++) {
                if (trim($lines[$next]) === '') {
                    continue;
                }

                if ($indent($lines[$next]) > $indent($line)) {
                    $lines[$index] = $match[1];
                }

                break;
            }
        }

        return $lines;
    }

    /**
     * Every dotted path in a parsed tree, with a list ENTRY contributing the wildcard segment its
     * index is not — so `export.targets.*.format` is the one answer however many targets are written.
     *
     * @param  array<array-key, mixed>  $bag
     * @return list<string>
     */
    private static function paths(array $bag, string $prefix): array
    {
        $paths = [];
        $list = array_is_list($bag);

        foreach ($bag as $key => $value) {
            $path = self::join($prefix, $list ? '*' : (string) $key);

            if (! $list) {
                $paths[] = $path;
            }

            if (is_array($value)) {
                $paths = array_merge($paths, self::paths($value, $path));
            }
        }

        return $paths;
    }

    private static function join(string $prefix, string $path): string
    {
        return $prefix === '' ? $path : $prefix.'.'.$path;
    }
}
