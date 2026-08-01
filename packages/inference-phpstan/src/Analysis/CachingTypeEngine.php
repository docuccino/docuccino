<?php

declare(strict_types=1);

namespace Docuccino\Inference\PhpStan\Analysis;

use Docuccino\Core\Inference\ActionAnalysis;
use Docuccino\Core\Inference\ActionRef;
use Docuccino\Core\Inference\ClassMetadata;
use Docuccino\Core\Inference\ClassRef;
use Docuccino\Core\Inference\TraceVisitor;
use Docuccino\Core\Inference\TypeEngine;
use Docuccino\Inference\PhpStan\Cache\EngineResultCache;
use Docuccino\Inference\PhpStan\Cache\VersionFingerprint;

/**
 * A cache decorator around any {@see TypeEngine} (design §8). `analyzeAction` and
 * `classMetadata` are cacheable — served from the {@see EngineResultCache} on a
 * hit, recomputed then stored on a miss. `trace()` is never cached: it hands
 * live `PhpParser\Node`s to a stateful visitor, which cannot round-trip through a
 * serialized store — it always delegates to the inner engine.
 *
 * The hit path returns a value byte-identical to the miss path because the cache
 * stores the canonical `toArray()` and the decorator returns the same object it
 * would have computed.
 */
final readonly class CachingTypeEngine implements TypeEngine
{
    public function __construct(
        private TypeEngine $inner,
        private EngineResultCache $cache,
        private VersionFingerprint $fingerprint,
    ) {}

    public function analyzeAction(ActionRef $action): ActionAnalysis
    {
        $cached = $this->cache->getAction($action, $this->fingerprint);
        if ($cached !== null) {
            return $cached;
        }

        $analysis = $this->inner->analyzeAction($action);
        $this->cache->putAction($action, $analysis, $this->fingerprint);

        return $analysis;
    }

    public function classMetadata(ClassRef $class): ClassMetadata
    {
        $cached = $this->cache->getClass($class, $this->fingerprint);
        if ($cached !== null) {
            return $cached;
        }

        $metadata = $this->inner->classMetadata($class);
        $this->cache->putClass($class, $metadata, $this->fingerprint);

        return $metadata;
    }

    public function trace(ActionRef $action, TraceVisitor $visitor): void
    {
        $this->inner->trace($action, $visitor);
    }
}
