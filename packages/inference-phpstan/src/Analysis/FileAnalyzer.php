<?php

declare(strict_types=1);

namespace Docuccino\Inference\PhpStan\Analysis;

use Docuccino\Inference\PhpStan\Runtime\RuntimeAdapter;
use PhpParser\Node;
use PHPStan\Analyser\Scope;
use PHPStan\Node\MethodReturnStatementsNode;

/**
 * Parses a file once and harvests its virtual `MethodReturnStatementsNode`s,
 * keyed by method name — the structured-harvest node that pairs every `return`
 * with its flow-refined scope and carries the method's throw points (design §2).
 * Memoised per file so descent re-uses a single rich parse; the adapter's
 * priming guarantees bodies survive.
 */
final class FileAnalyzer
{
    /** @var array<string, array<string, MethodReturnStatementsNode>> */
    private array $cache = [];

    public function __construct(private readonly RuntimeAdapter $adapter) {}

    /**
     * @return array<string, MethodReturnStatementsNode>
     */
    public function analyze(string $file): array
    {
        $normalised = $this->adapter->normalize($file);
        if (isset($this->cache[$normalised])) {
            return $this->cache[$normalised];
        }

        $collected = [];
        $this->adapter->processFile($file, static function (Node $node, Scope $scope) use (&$collected): void {
            // The documented structured-harvest node (design §2, Spike A): watching
            // for it is the sanctioned way to pair returns with flow-refined scope.
            // @phpstan-ignore phpstanApi.instanceofAssumption
            if ($node instanceof MethodReturnStatementsNode) {
                $collected[$node->getMethodName()] = $node;
            }
        });

        return $this->cache[$normalised] = $collected;
    }
}
