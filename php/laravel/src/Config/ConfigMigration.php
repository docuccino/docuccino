<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Config;

use Docuccino\Core\Emit\YamlSerializer;
use Docuccino\Core\Support\Hydrate;
use Docuccino\Laravel\Commands\MigrateConfigCommand;

/**
 * One reading of `config/docuccino.php` as the `docuccino.yaml` it should have become: the settings to
 * write, and everything the move could not carry with them.
 *
 * {@see ConfigSplit} decides which keys are build keys and this decides what becomes of each of them,
 * which is three different things. Most are copied under the name they already have. A few were
 * RENAMED and are copied under the new one, because a migration that wrote the old spelling would
 * hand back a file the build reports as unknown — its author would have run the remedy and still be
 * holding a setting nothing reads. And a few were REMOVED, which is the only interesting case:
 * dropping a key in silence is the confidently-wrong answer the refusal this fixes exists to prevent,
 * so every drop is accounted for, and the ones that CHANGE the document are kept apart from the ones
 * that never did anything.
 *
 * Values arrive resolved, because `config()` is what read them: an `env()` call in the framework
 * config has already become whatever the variable said on this machine, and no indirection survives
 * in the value. {@see environment()} names the ones the tool itself put behind a variable, which are
 * the only ones still visible from here.
 *
 * @internal
 */
final readonly class ConfigMigration
{
    /**
     * Build settings that changed their spelling, as the framework-config path against the
     * `docuccino.yaml` path — `*` standing for a document key on both sides.
     *
     * @var array<string, string>
     */
    public const array RENAMED = [
        // The setting says which requests are authenticated; the old name said how it found out.
        'documents.*.security.auto_detect_middleware' => 'documents.*.security.auth_middleware',
        // The engine takes any analyser configuration file, and its extension is not the key's to fix.
        'engine.neon' => 'engine.config',
    ];

    /**
     * Build settings `docuccino.yaml` has no key for, against whether dropping one changes the
     * document it was written for.
     *
     * `shaped` is the whole distinction and it decides how loud a drop is. A key that shaped the
     * document leaves a file describing something other than it used to, which its author has to know
     * before the next export. A key no reader ever read leaves a byte-identical document, and
     * reporting that as a loss would put a line nobody can act on above the line they must.
     *
     * `cost` is read only where a drop really lost something, so it is written for that case and not
     * as a general note about the key.
     *
     * @var array<string, array{shaped: bool, cost: string}>
     */
    public const array REMOVED = [
        // A closure filtered routes and could not be fingerprinted, so a build could never tell
        // whether a cached fragment was still the answer. `routes.filter` names a class instead, and
        // nothing turns a closure into one automatically.
        'documents.*.routes.closure' => [
            'shaped' => true,
            'cost' => 'a closure has no form in a configuration file, so the routes it held back are documented again',
        ],
        // Both of its values always emitted the comma form; nothing ever branched on it.
        'documents.*.representation.lists' => [
            'shaped' => false,
            'cost' => 'no reader ever read it, and both of its values emitted the same document',
        ],
    ];

    /**
     * @param  array<string, mixed>  $settings  the build settings, as `docuccino.yaml` should hold them
     * @param  list<string>  $lost  paths dropped whose value shaped the document — the file is incomplete
     * @param  list<string>  $dropped  paths dropped that shaped nothing, so the document is unchanged
     * @param  array<string, string>  $renamed  the path as it was written => the path written instead
     * @param  array<string, string>  $environment  path => the variable this machine's value came from
     * @param  bool  $resolved  whether `config/docuccino.php` reads any setting through `env()` at all
     */
    private function __construct(
        public array $settings,
        public array $lost,
        public array $dropped,
        public array $renamed,
        public array $environment,
        public bool $resolved,
    ) {}

    /** Read `config/docuccino.php` as it stands, through the split that owns which keys are build keys. */
    public static function of(): self
    {
        return self::from(ConfigSplit::buildSettings());
    }

    /**
     * The same reading of a bag handed in, which is what lets the transform be stated against an input
     * rather than only against an application.
     *
     * @param  array<string, mixed>  $settings
     */
    public static function from(array $settings): self
    {
        $lost = [];
        $dropped = [];
        $renamed = [];

        foreach (self::REMOVED as $path => $removal) {
            foreach (self::written($settings, $path) as $at) {
                $value = self::pull($settings, $at);
                $name = implode('.', $at);

                // A key present and null has expressed nothing, so dropping it takes nothing with it
                // — and `closure` shipped AS null in the file every application published, which would
                // otherwise fire the loudest line here on nearly every migration there is.
                if ($removal['shaped'] && $value !== null) {
                    $lost[] = $name;

                    continue;
                }

                $dropped[] = $name;
            }
        }

        foreach (self::RENAMED as $path => $to) {
            foreach (self::written($settings, $path) as $at) {
                $target = self::rewritten($at, $path, $to);

                self::put($settings, $target, self::pull($settings, $at));
                $renamed[implode('.', $at)] = implode('.', $target);
            }
        }

        sort($lost, SORT_STRING);
        sort($dropped, SORT_STRING);
        ksort($renamed, SORT_STRING);

        $source = self::source();

        return new self(
            $settings,
            $lost,
            $dropped,
            $renamed,
            self::environment($settings, $source),
            str_contains($source, 'env('),
        );
    }

    /** Whether the migration carried over everything it was given. */
    public function complete(): bool
    {
        return $this->lost === [];
    }

    /** Whether there was anything to migrate at all. */
    public function nothing(): bool
    {
        return $this->settings === [];
    }

    /**
     * The file to write: the settings, under a header saying where they came from and naming whatever
     * did not come with them.
     *
     * The header is the one place a loss is recorded where it cannot be lost. A console line scrolls
     * away and {@see MigrateConfigCommand} is run once, so a comment in the file its author opens next
     * is what keeps "you still owe this document a RouteFilter" true tomorrow. A comment is also all
     * such a note can be: a placeholder KEY would join the resolved configuration the moment anybody
     * uncommented it, and change the document's fingerprint for a setting nobody set.
     *
     * No defaults and no catalogue of options, which is where this parts company with the shipped
     * template. That file documents every setting there is; this one holds the settings one
     * application actually has, because a key written here its author never set is a key their
     * document is fingerprinted from.
     */
    public function file(): string
    {
        $lines = [
            sprintf('# Written by `php artisan %s` from config/docuccino.php.', MigrateConfigCommand::NAME),
            '#',
            '# These are that file\'s own build settings, carried over as they stood. Everything not',
            '# written here takes its documented default; the configuration reference lists them all.',
        ];

        foreach ($this->lost as $path) {
            $lines[] = '#';
            $lines[] = '# NOT carried over: '.$path;
            $lines[] = '#   '.self::cost($path).'.';
            $lines[] = '#   Until that is settled this document is not the one config/docuccino.php described.';
        }

        foreach ($this->environment as $path => $variable) {
            $lines[] = '#';
            $lines[] = sprintf('# %s was read from %s, so the value below is the one set where this ran.', $path, $variable);
            $lines[] = sprintf('#   %s still overrides this file, so a single run can go on setting it.', $variable);
        }

        return implode("\n", $lines)."\n\n".(new YamlSerializer)->serialize($this->settings);
    }

    /** What dropping `$path` cost, read off {@see REMOVED} by the key the path matched. */
    public static function cost(string $path): string
    {
        $wildcarded = implode('.', DeclaredSettings::wildcarded(explode('.', $path)));

        return self::REMOVED[$wildcarded]['cost'] ?? 'it is not a setting docuccino.yaml has a key for';
    }

    /**
     * The settings whose value this machine's environment really decided, path => the variable that
     * decided it.
     *
     * Three things have to hold at once, and dropping any of them makes the report a claim this cannot
     * check. The variable has to be SET here, or the value written is the file's own fallback and
     * nothing was decided elsewhere. The setting has to be written, or there is no value to talk
     * about. And `config/docuccino.php` has to NAME that variable — a resolved value carries no trace
     * of the call behind it, so without reading the source this would be telling an author their
     * literal `'in-process'` came from an environment it never touched.
     *
     * Only the tool's own levers, because they are the only ones whose spelling is known: the
     * variables in {@see BuildConfig::ENV_OVERRIDES} were `env()` calls in the shipped framework
     * config, and they still override this file, so a value baked out of one is this machine's answer
     * AND remains overridable. An `env()` call an application put on some other key is gone by the
     * time `config()` answers, and {@see $resolved} says so rather than guessing which.
     *
     * @param  array<string, mixed>  $settings
     * @return array<string, string>
     */
    private static function environment(array $settings, string $source): array
    {
        $read = [];

        foreach (BuildConfig::ENV_OVERRIDES as $variable => $path) {
            // Read with the variable's own NAME as the fallback, the way `BuildConfig` reads it: it is
            // a value no environment would carry, so "set to null" stays distinguishable from unset.
            if (env($variable, $variable) === $variable || ! str_contains($source, $variable)) {
                continue;
            }

            if (self::has($settings, explode('.', $path))) {
                $read[$path] = $variable;
            }
        }

        ksort($read, SORT_STRING);

        return $read;
    }

    /**
     * The framework config as TEXT, empty where it cannot be read.
     *
     * The one thing a parsed array cannot answer is what was written to produce it, and every question
     * about `env()` is that question. Empty rather than a failure, because a migration whose source
     * file has already been deleted still has `config()` to read and a file to write.
     */
    private static function source(): string
    {
        return (string) @file_get_contents(config_path('docuccino.php'));
    }

    /**
     * Every place `$path` is really written in `$settings`, as segment lists — one per document where
     * the path holds a `*`, and none where the key is not written at all.
     *
     * Presence and never a value, because the key this matters most for shipped as null: reading
     * `closure => null` as absent would leave it in the file, where the build reports a key it has no
     * setting for and the author is sent looking for a typo they did not make.
     *
     * @param  array<string, mixed>  $settings
     * @return list<list<string>>
     */
    private static function written(array $settings, string $path): array
    {
        $segments = explode('.', $path);
        $at = array_search('*', $segments, true);

        if ($at === false) {
            return self::has($settings, $segments) ? [$segments] : [];
        }

        $written = [];

        foreach (array_keys(Hydrate::map($settings[$segments[0]] ?? null)) as $document) {
            $candidate = [];
            foreach ($segments as $index => $segment) {
                $candidate[] = $index === $at ? $document : $segment;
            }

            if (self::has($settings, $candidate)) {
                $written[] = $candidate;
            }
        }

        return $written;
    }

    /**
     * `$at` with the segments `$from` fixes replaced by `$to`'s, keeping the ones it wildcarded.
     *
     * @param  list<string>  $at
     * @return list<string>
     */
    private static function rewritten(array $at, string $from, string $to): array
    {
        $old = explode('.', $from);
        $new = explode('.', $to);

        foreach ($new as $index => $segment) {
            if ($segment === '*' && ($old[$index] ?? null) === '*') {
                $new[$index] = $at[$index];
            }
        }

        return $new;
    }

    /**
     * @param  array<string, mixed>  $settings
     * @param  list<string>  $path
     */
    private static function has(array $settings, array $path): bool
    {
        $leaf = array_pop($path);

        if ($leaf === null) {
            return false;
        }

        $node = $settings;

        foreach ($path as $segment) {
            if (! is_array($node[$segment] ?? null)) {
                return false;
            }

            /** @var array<string, mixed> $node */
            $node = $node[$segment];
        }

        return array_key_exists($leaf, $node);
    }

    /**
     * The value at `$path`, removing the key as it reads it.
     *
     * @param  array<string, mixed>  $settings
     * @param  list<string>  $path
     */
    private static function pull(array &$settings, array $path): mixed
    {
        $leaf = array_pop($path);

        if ($leaf === null) {
            return null;
        }

        $node = &$settings;

        foreach ($path as $segment) {
            if (! is_array($node[$segment] ?? null)) {
                return null;
            }

            $node = &$node[$segment];
        }

        $value = $node[$leaf] ?? null;
        unset($node[$leaf]);
        unset($node);

        return $value;
    }

    /**
     * @param  array<string, mixed>  $settings
     * @param  list<string>  $path
     */
    private static function put(array &$settings, array $path, mixed $value): void
    {
        $node = &$settings;

        foreach ($path as $segment) {
            if (! is_array($node[$segment] ?? null)) {
                $node[$segment] = [];
            }

            $node = &$node[$segment];
        }

        $node = $value;
        unset($node);
    }
}
