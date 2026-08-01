<?php

declare(strict_types=1);

namespace Docuccino\Inference\PhpStan\Analysis;

use Docuccino\Core\Inference\NullTypeEngine;
use Docuccino\Core\Inference\TypeEngine;
use Docuccino\Inference\PhpStan\Metadata\ClassMetadataFactory;
use Docuccino\Inference\PhpStan\Runtime\BootFailedException;
use Docuccino\Inference\PhpStan\Runtime\RuntimeAdapterFactory;
use Docuccino\Inference\PhpStan\Runtime\RuntimeConfig;
use Docuccino\Inference\PhpStan\Runtime\UnsupportedPhpStanVersionException;
use Docuccino\Inference\PhpStan\Support\ProjectFilter;
use Docuccino\Inference\PhpStan\Translation\TypeTranslator;

/**
 * Builds a booted {@see PhpStanTypeEngine}, wiring the per-minor adapter,
 * translator, file analyzer, project filter and metadata factory. If the
 * container/Larastan bootstrap fails or the PHPStan version is unsupported, it
 * degrades to {@see NullTypeEngine} so docblock/attribute-only docs still build
 * (design §3) — the caller always gets a total {@see TypeEngine}.
 */
final class PhpStanEngineFactory
{
    public function __construct(
        private readonly RuntimeAdapterFactory $adapterFactory = new RuntimeAdapterFactory,
    ) {}

    public function create(RuntimeConfig $runtimeConfig, EngineConfig $engineConfig): TypeEngine
    {
        try {
            $adapter = $this->adapterFactory->create($runtimeConfig);
            $adapter->boot();
        } catch (BootFailedException|UnsupportedPhpStanVersionException) {
            return new NullTypeEngine;
        }

        $translator = new TypeTranslator;
        $fileAnalyzer = new FileAnalyzer($adapter);
        $projectFilter = new ProjectFilter(
            $engineConfig->projectPaths,
            static fn (string $path): string => $adapter->normalize($path),
        );

        return new PhpStanTypeEngine(
            adapter: $adapter,
            config: $engineConfig,
            translator: $translator,
            fileAnalyzer: $fileAnalyzer,
            projectFilter: $projectFilter,
            classMetadataFactory: new ClassMetadataFactory,
        );
    }
}
