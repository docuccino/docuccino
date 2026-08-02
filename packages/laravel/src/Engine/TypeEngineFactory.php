<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Engine;

use Docuccino\Core\Inference\NullTypeEngine;
use Docuccino\Core\Inference\TypeEngine;
use Docuccino\Inference\PhpStan\Analysis\EngineConfig;
use Docuccino\Inference\PhpStan\Analysis\PhpStanEngineFactory;
use Docuccino\Inference\PhpStan\Runtime\RuntimeConfig;

/**
 * Builds the configured {@see TypeEngine} for a build (design §Inference). The `null` mode skips
 * PHPStan entirely; `in-process` boots the real engine — and {@see PhpStanEngineFactory::create()}
 * already degrades to a {@see NullTypeEngine} on any container/Larastan boot failure, so the
 * caller always gets a total engine and the build stays alive. Caching/orchestrated composition
 * is retained in the enum for Phase 3b; Phase 3a treats them as in-process.
 */
final readonly class TypeEngineFactory
{
    public function __construct(
        private string $basePath,
        private string $tmpDir,
        private PhpStanEngineFactory $factory = new PhpStanEngineFactory,
    ) {}

    /**
     * @param  array<string, mixed>  $config  the `docuccino.engine` config array
     */
    public function make(array $config): TypeEngine
    {
        $mode = TypeEngineMode::tryFrom(is_string($config['mode'] ?? null) ? $config['mode'] : '')
            ?? TypeEngineMode::InProcess;

        if ($mode === TypeEngineMode::Null) {
            return new NullTypeEngine;
        }

        $projectPaths = $this->projectPaths($config);

        if (! is_dir($this->tmpDir)) {
            @mkdir($this->tmpDir, 0755, true);
        }

        $runtime = new RuntimeConfig(
            projectRoot: $this->basePath,
            tmpDir: $this->tmpDir,
            phpVersion: PHP_VERSION_ID,
            projectPaths: $projectPaths,
        );

        return $this->factory->create($runtime, EngineConfig::forProject(...$projectPaths));
    }

    /**
     * @param  array<string, mixed>  $config
     * @return list<string>
     */
    private function projectPaths(array $config): array
    {
        $paths = $config['project_paths'] ?? ['app'];
        if (! is_array($paths)) {
            $paths = ['app'];
        }

        $out = [];
        foreach ($paths as $path) {
            if (is_string($path)) {
                $out[] = $this->basePath.'/'.ltrim($path, '/');
            }
        }

        return $out === [] ? [$this->basePath.'/app'] : $out;
    }
}
