<?php

declare(strict_types=1);

namespace Docuccino\Inference\PhpStan\Metadata;

use Docuccino\Core\Inference\ClassMetadata;
use Docuccino\Core\Inference\ClassRef;
use Docuccino\Core\Inference\PropertyMetadata;
use Docuccino\Core\Inference\SourceLocation;
use ReflectionClass;
use ReflectionProperty;

/**
 * Builds {@see ClassMetadata} from native reflection + docblocks (design §4,
 * "Data/Resource/Model shapes, lazy + memoised"). Property types come from
 * native reflection (no analysis scope needed); prose + `@example` come from
 * {@see DocBlockReader}. Memoised per class per run; always total (an
 * unresolvable class yields an empty, well-formed metadata).
 */
final class ClassMetadataFactory
{
    /** @var array<string, ClassMetadata> */
    private array $cache = [];

    public function __construct(
        private readonly DocBlockReader $docBlocks = new DocBlockReader,
        private readonly NativeTypeMapper $typeMapper = new NativeTypeMapper,
    ) {}

    public function forClass(ClassRef $class): ClassMetadata
    {
        if (isset($this->cache[$class->fqcn])) {
            return $this->cache[$class->fqcn];
        }

        return $this->cache[$class->fqcn] = $this->build($class->fqcn);
    }

    private function build(string $fqcn): ClassMetadata
    {
        if (! class_exists($fqcn)) {
            return new ClassMetadata($fqcn);
        }

        $reflection = new ReflectionClass($fqcn);
        $file = $reflection->getFileName();
        $properties = [];
        foreach ($reflection->getProperties(ReflectionProperty::IS_PUBLIC) as $property) {
            if ($property->isStatic()) {
                continue;
            }
            $docComment = $property->getDocComment();
            $docComment = $docComment === false ? null : $docComment;
            $properties[] = new PropertyMetadata(
                name: $property->getName(),
                type: $this->typeMapper->map($property->getType()),
                summary: $this->docBlocks->summary($docComment),
                example: $this->docBlocks->example($docComment),
                location: $file !== false ? new SourceLocation($file) : null,
            );
        }

        $classDoc = $reflection->getDocComment();

        return new ClassMetadata(
            fqcn: $fqcn,
            properties: $properties,
            summary: $this->docBlocks->summary($classDoc === false ? null : $classDoc),
        );
    }
}
