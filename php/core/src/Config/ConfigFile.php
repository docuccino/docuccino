<?php

declare(strict_types=1);

namespace Docuccino\Core\Config;

use Docuccino\Core\Diagnostics\Diagnostic;
use Docuccino\Core\Diagnostics\Severity;
use Docuccino\Core\Support\Arr;
use Docuccino\Core\Support\PlainText;
use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml;

/**
 * One read of the tool's own configuration file: the absolute path it was found at, the top-level map
 * it parsed to, why it isn't one when it isn't, and the diagnostics that say so. Every failure is a
 * value here — nothing this class does throws, because a build must survive a config file somebody is
 * halfway through editing.
 *
 * The seam is deliberate. The CALLER supplies the directory, because finding a project root is a
 * question about the host, and this package knows nothing about hosts. Everything downstream of that
 * directory — which name the file has, how it parses, what a broken one degrades to — is the tool's own
 * and lives here. Diagnostics therefore name the file by its root-relative name and never by the path,
 * so two machines building the same commit report the same bytes.
 *
 * No configuration is a legitimate state and gets no diagnostic: a correct document with no config is
 * the product. A file that exists and cannot be read is the opposite, and every one of those states is
 * an {@see $error} with a diagnostic beside it.
 *
 * @internal
 */
final class ConfigFile
{
    /** The one name a project's configuration is read from. */
    public const string NAME = 'docuccino.yaml';

    /**
     * Spellings that are not {@see NAME} but are obviously trying to be, and what each is missing.
     *
     * A configuration file silently ignored because of a character is the worst failure this class has,
     * because the symptom is a document that does not match the file the author edited — so a near miss
     * beside no real file is reported rather than passed over. One name and no fallbacks is the point:
     * two accepted spellings would owe a precedence rule, and precedence between two config files is a
     * thing nobody should have to know.
     *
     * @var array<string, string>
     */
    private const NEAR_MISSES = [
        'docuccino.yml' => 'the extension is spelled ".yaml" in full',
        '.docuccino.yaml' => 'the name is not a dotfile',
        '.docuccino.yml' => 'the name is not a dotfile, and the extension is spelled ".yaml" in full',
        'docuccino.yaml.dist' => 'the file is read as-is, not from a ".dist" template',
        'docuccino.json' => 'the configuration is YAML',
        'docuccino.neon' => 'the configuration is YAML',
    ];

    /**
     * The parse flags. `PARSE_EXCEPTION_ON_INVALID_TYPE` is the whole list and it is not optional:
     * WITHOUT it, `!php/const`, `!php/enum` and `!php/object` parse to NULL with no word said, which is
     * indistinguishable from a key the author wrote as null — a value silently lost inside a hash that
     * keys the fragment cache. With it they are a {@see ParseException} this class reports. Measured
     * against every spelling in the hostile-scalar corpus, the flag changes nothing else.
     *
     * Notably absent: `PARSE_DATETIME` would hand back `DateTimeImmutable` objects the canonical writer
     * has no form for, and `PARSE_CUSTOM_TAGS` and `PARSE_CONSTANT` would each turn a stricter parse
     * error into a value. Refusing what we cannot represent is the whole design.
     */
    private const FLAGS = Yaml::PARSE_EXCEPTION_ON_INVALID_TYPE;

    /** No file of that name in the directory. Not an error: zero configuration is a supported state. */
    public const string ABSENT = 'absent';

    /** The file is there and could not be read. */
    public const string UNREADABLE = 'unreadable';

    /** The file is there and is not YAML this parses — malformed, a duplicate key, a tab, a PHP tag. */
    public const string INVALID = 'invalid';

    /** The file parses, and to something other than a map. An empty file is this. */
    public const string NOT_A_MAP = 'not-a-map';

    /**
     * @param  array<string, mixed>  $values
     * @param  list<Diagnostic>  $diagnostics
     */
    private function __construct(
        public readonly ?string $path,
        public readonly array $values,
        public readonly ?string $error,
        public readonly array $diagnostics = [],
    ) {}

    /** Memoised, because the reader accumulates its refusals and a second one would start empty. */
    private ?ConfigValues $reader = null;

    /**
     * Read the configuration out of `$directory`, which is the project root the caller resolved.
     *
     * {@see $path} is set whenever a file was found, failure included, because the caller registers it
     * as a cache dependency either way — a file that does not parse today must rebuild when it does.
     * An ABSENT read carries the path it looked at for the same reason: the build has to notice the
     * file appearing.
     */
    public static function read(string $directory): self
    {
        $path = rtrim($directory, '/\\').DIRECTORY_SEPARATOR.self::NAME;

        if (! is_file($path)) {
            return new self($path, [], self::ABSENT, self::misnamed($directory));
        }

        $contents = @file_get_contents($path);
        if ($contents === false) {
            return new self($path, [], self::UNREADABLE, [new Diagnostic(
                severity: Severity::Error,
                code: 'config.file-unreadable',
                message: sprintf(
                    '%s is there and could not be read, so the document is built from defaults alone.',
                    self::NAME,
                ),
                help: 'Check the file\'s permissions.',
            )]);
        }

        $parsed = self::parse($contents);

        return new self($path, $parsed->values, $parsed->error, $parsed->diagnostics);
    }

    /**
     * Parse configuration text. Split out from {@see read()} so the parse and the shape can be stated
     * against a string, with no directory in the way.
     */
    public static function parse(string $contents): self
    {
        // A byte-order mark is not content, and a parser has no reason to treat it as any. Left in
        // place it lands INSIDE the first key's name, so `documents:` becomes a key nothing reads and
        // the whole file goes quietly unapplied.
        $contents = self::withoutByteOrderMark($contents);

        try {
            $value = Yaml::parse($contents, self::FLAGS);
        } catch (ParseException $exception) {
            return new self(null, [], self::INVALID, [new Diagnostic(
                severity: Severity::Error,
                code: 'config.file-invalid',
                message: sprintf(
                    '%s is not valid YAML, so the document is built from defaults alone: %s',
                    self::NAME,
                    // The message quotes the line it choked on, so it carries file bytes — and a file
                    // is authored text that can steer a terminal or forge a line of a CI log.
                    PlainText::of($exception->getMessage()),
                ),
                help: 'Fix the line named above and run the build again.',
            )]);
        }

        // An empty file parses to null, and a document is a map or it says nothing. Reading either as
        // an empty array is the failure worth the most care here: the build would then run on every
        // default and produce a plausible document, so the author's file looks applied and is not.
        if (! is_array($value) || array_is_list($value)) {
            return new self(null, [], self::NOT_A_MAP, [new Diagnostic(
                severity: Severity::Error,
                code: 'config.file-not-a-map',
                message: sprintf(
                    '%s holds %s where it must hold a map of settings, so the document is built from defaults alone.',
                    self::NAME,
                    self::described($value),
                ),
                help: 'Write the settings as top-level `key: value` pairs.',
            )]);
        }

        // Absent and present-null stay different all the way through, so nothing is stripped here.
        // A reader downstream distinguishes them — a key nobody named has expressed nothing and takes
        // its documented fallback, while a key present and unreadable has an author behind it and
        // degrades loudly. Dropping nulls here would collapse the two into one, silently.
        return new self(null, Arr::stringKeyed($value), null);
    }

    public function ok(): bool
    {
        return $this->error === null;
    }

    /**
     * The parsed settings, as a reader that refuses a wrong type rather than converting it.
     *
     * The same reader every time: it collects the refusals it has made, and handing back a fresh one
     * per call would lose every refusal but the last caller's.
     */
    public function values(): ConfigValues
    {
        return $this->reader ??= ConfigValues::of($this->values);
    }

    /**
     * A near-miss name sitting beside no real file, as one diagnostic. Nothing when the author has
     * simply not written a configuration file, which is most projects.
     *
     * @return list<Diagnostic>
     */
    private static function misnamed(string $directory): array
    {
        $root = rtrim($directory, '/\\').DIRECTORY_SEPARATOR;
        $found = [];

        // Iterated over the constant rather than the directory, so the answer is the table's order and
        // not the filesystem's.
        foreach (self::NEAR_MISSES as $name => $reason) {
            if (is_file($root.$name)) {
                $found[] = sprintf('%s (%s)', $name, $reason);
            }
        }

        if ($found === []) {
            return [];
        }

        return [new Diagnostic(
            severity: Severity::Warning,
            code: 'config.file-misnamed',
            message: sprintf(
                'There is no %s, so the document is built from defaults alone — but %s %s, which is not a name the configuration is read from: %s.',
                self::NAME,
                count($found) === 1 ? 'there is a' : 'there are',
                count($found) === 1 ? 'file named' : 'files named',
                implode(', ', $found),
            ),
            help: sprintf('Rename it to %s.', self::NAME),
        )];
    }

    /** What the root turned out to be, in words an author can match against what they wrote. */
    private static function described(mixed $value): string
    {
        return match (true) {
            $value === null => 'nothing (the file is empty, or its only content is a comment)',
            is_array($value) => 'a list',
            is_bool($value) => sprintf('the single value %s', $value ? 'true' : 'false'),
            is_string($value) => 'a single line of text',
            default => 'a single number',
        };
    }

    private static function withoutByteOrderMark(string $contents): string
    {
        return str_starts_with($contents, "\xEF\xBB\xBF") ? substr($contents, 3) : $contents;
    }
}
