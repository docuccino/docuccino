<?php

declare(strict_types=1);

namespace Docuccino\Core\Inference\DType;

/**
 * A constant array shape (`array{id: int, name?: string}` or a positional
 * list-shape). Field order is significant and preserved verbatim — it is not
 * sorted, unlike union members. `isList` records whether the shape's keys are a
 * `0..n` sequence (from PHPStan's list accessory).
 */
final readonly class ArrayShapeT extends DType
{
    public const KIND = 'arrayShape';

    /**
     * @param  list<ArrayShapeField>  $fields
     */
    public function __construct(public array $fields, public bool $isList = false) {}

    public function kind(): string
    {
        return self::KIND;
    }

    public function toArray(): array
    {
        return [
            'kind' => self::KIND,
            'isList' => $this->isList,
            'fields' => array_map(static fn (ArrayShapeField $f): array => $f->toArray(), $this->fields),
        ];
    }

    /**
     * @param  array<array-key, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        $fields = $data['fields'] ?? [];

        return new self(
            is_array($fields)
                ? array_values(array_map(
                    static fn (mixed $f): ArrayShapeField => is_array($f)
                        ? ArrayShapeField::fromArray($f)
                        : new ArrayShapeField('', new UnknownT('malformed field')),
                    $fields,
                ))
                : [],
            (bool) ($data['isList'] ?? false),
        );
    }
}
