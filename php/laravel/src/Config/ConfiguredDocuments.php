<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Config;

use Docuccino\Core\Extensions\Context\DocumentConfig;
use Docuccino\Core\Support\Hydrate;
use Docuccino\Core\Versioning\VersionOrder;

/**
 * The `documents` bag out of `docuccino.yaml`: which documents this application configures, and the
 * raw entry for one of them. Every reader of the key set comes here, so the set a build resolves and
 * the set an `#[InDocs]` key is judged against cannot disagree.
 *
 * The set is never empty ({@see of()}). No configuration is a legitimate state — an absent file, one
 * that would not parse, one that names no `documents` at all — and each of those states already says
 * the document is built from defaults alone. With no document there would BE no document: the build
 * loop would run zero times, so nothing would be written and none of those sentences would be said.
 *
 * Asked of {@see BuildConfig} rather than memoised here. That reader holds ONE parse per build for
 * its own reasons, and a second cache in front of it would answer a build with the configuration of
 * the build before it.
 *
 * @internal
 */
final class ConfiguredDocuments
{
    /** The document an application gets when its configuration names none. */
    public const string DEFAULT_KEY = 'default';

    /**
     * The bag as configured, keyed by document key, with the fallback applied.
     *
     * @return array<string, mixed>
     */
    public function all(): array
    {
        return self::of(app(BuildConfig::class));
    }

    /**
     * `$build`'s documents, falling back to one `default` document when it names none.
     *
     * Stated here once and asked for by the readers that hold a {@see BuildConfig} already, because a
     * reader working off the raw bag would judge viewers, `#[InDocs]` keys and route filters against a
     * document set the build does not have.
     *
     * A default rather than the shipped file folded in as defaults: the shipped `docuccino.yaml` shows
     * every option with its default, so merging it under an author's file would put ~130 keys they
     * never wrote into the bag `document.configHash` is taken over — changing emitted bytes for every
     * application with a partial file — and would add a `default` document beside the ones a
     * multi-document app declared. The readers already own their defaults, so an empty bag resolves to
     * exactly the document the shipped file describes.
     *
     * @return array<string, mixed>
     */
    public static function of(BuildConfig $build): array
    {
        $configured = $build->documents();

        return $configured === [] ? [self::DEFAULT_KEY => []] : $configured;
    }

    /**
     * The configured keys, in config declaration order — the order they are written in is the order
     * their author reads them in, so a message listing them lists them that way.
     *
     * @return list<string>
     */
    public function keys(): array
    {
        return array_map(
            static fn (int|string $key): string => (string) $key,
            array_keys($this->all()),
        );
    }

    /**
     * Every version the application configures, sorted — the closed set a version header enumerates, read
     * off the documents themselves so there is no second list to keep in step with them. A document that
     * declares `api_version` and states no version of its own contributes nothing: its own build says so.
     *
     * Sorted by {@see VersionOrder}, never bytewise: `1.10.0` before `1.9.0` is the reading the whole of
     * versioning exists to replace, and publishing it in the enum a consumer reads would be that reading
     * shipped in the artifact.
     *
     * @return list<string>
     */
    public function apiVersions(): array
    {
        $versions = [];
        foreach ($this->all() as $entry) {
            // What makes a document a version, and what its version IS, are DocumentConfig's rules; asking
            // it is what keeps the enum and the document that publishes it saying the same thing.
            $version = DocumentConfig::statedVersion(Hydrate::map($entry));

            if ($version !== null) {
                $versions[$version] = true;
            }
        }

        return VersionOrder::sorted(array_keys($versions));
    }

    /** Whether `$key` names a configured document. */
    public function has(string $key): bool
    {
        return is_array($this->all()[$key] ?? null);
    }

    /**
     * One document's raw configuration, empty when it names none.
     *
     * @return array<string, mixed>
     */
    public function raw(string $key): array
    {
        return Hydrate::map($this->all()[$key] ?? null);
    }
}
