<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Pipeline;

use JsonException;

/**
 * The OperationFragment cache (design §10): keys a route's built fragment on everything that could
 * change its output *except* the dependency files, then validates freshness by re-hashing the
 * stored dependency list. A hit therefore reconstructs the fragment without invoking the type
 * engine.
 *
 * key = sha256(tool ver ‖ spec ver ‖ identity-algo ver ‖ doc configHash ‖ resolved extension FQCNs
 * ‖ route signature). The stored entry additionally records `sha256(each ActionAnalysis
 * dependency file)`; on lookup any changed/removed dependency invalidates the entry. (TraceReport
 * dependencies merge into the same list in Phase 4 — the {@see put()} signature is the seam.)
 *
 * Storage is a flat directory of `{key}.json` files written atomically (temp file + rename), with a
 * simple `enabled` off-switch.
 */
final readonly class FragmentCache
{
    public function __construct(
        private bool $enabled,
        private string $path,
        private string $toolVersion,
        private string $specVersion,
        private string $identityVersion,
    ) {}

    public function enabled(): bool
    {
        return $this->enabled;
    }

    /**
     * @param  list<string>  $extensionFqcns  the resolved extension class-strings, in resolve order
     */
    public function key(string $routeSignature, string $configHash, array $extensionFqcns): string
    {
        return hash('sha256', implode("\0", [
            $this->toolVersion,
            $this->specVersion,
            $this->identityVersion,
            $configHash,
            implode(',', $extensionFqcns),
            $routeSignature,
        ]));
    }

    /**
     * Return the cached fragment when the entry exists and every recorded dependency file still
     * hashes to its stored value; otherwise null (a miss, or an invalidated stale entry).
     */
    public function get(string $key): ?OperationFragment
    {
        if (! $this->enabled) {
            return null;
        }

        $raw = @file_get_contents($this->file($key));
        if ($raw === false) {
            return null;
        }

        try {
            /** @var array<string, mixed> $decoded */
            $decoded = json_decode($raw, true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return null;
        }

        $dependencies = is_array($decoded['dependencies'] ?? null) ? $decoded['dependencies'] : [];
        if (! $this->dependenciesFresh($dependencies)) {
            return null;
        }

        $fragment = $decoded['fragment'] ?? null;
        if (! is_array($fragment)) {
            return null;
        }

        /** @var array<string, mixed> $fragment */
        return OperationFragment::fromArray($fragment);
    }

    /**
     * @param  list<string>  $dependencyFiles  the ActionAnalysis dependency files for this route
     */
    public function put(string $key, OperationFragment $fragment, array $dependencyFiles): void
    {
        if (! $this->enabled) {
            return;
        }

        $dependencies = [];
        foreach (array_values(array_unique($dependencyFiles)) as $file) {
            $hash = @hash_file('sha256', $file);
            $dependencies[] = ['file' => $file, 'hash' => $hash === false ? '' : $hash];
        }

        try {
            $payload = json_encode(
                ['fragment' => $fragment->toArray(), 'dependencies' => $dependencies],
                JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES,
            );
        } catch (JsonException) {
            return;
        }

        $this->writeAtomically($this->file($key), $payload);
    }

    /**
     * @param  array<array-key, mixed>  $dependencies
     */
    private function dependenciesFresh(array $dependencies): bool
    {
        foreach ($dependencies as $dependency) {
            if (! is_array($dependency)) {
                return false;
            }

            $file = $dependency['file'] ?? null;
            $expected = $dependency['hash'] ?? null;
            if (! is_string($file) || ! is_string($expected)) {
                return false;
            }

            $current = @hash_file('sha256', $file);
            if ($current === false || $current !== $expected) {
                return false;
            }
        }

        return true;
    }

    private function file(string $key): string
    {
        return rtrim($this->path, '/').'/'.$key.'.json';
    }

    private function writeAtomically(string $file, string $contents): void
    {
        $directory = dirname($file);
        if (! is_dir($directory)) {
            @mkdir($directory, 0777, true);
        }

        $temp = $file.'.'.getmypid().'.'.bin2hex(random_bytes(4)).'.tmp';
        if (@file_put_contents($temp, $contents) === false) {
            return;
        }

        if (! @rename($temp, $file)) {
            @unlink($temp);
        }
    }
}
