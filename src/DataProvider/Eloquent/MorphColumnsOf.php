<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\DataProvider\Eloquent;

use Illuminate\Database\Eloquent\Model;

/**
 * The columns a `WhereMorph` compares on one model: the qualified type column with the
 * stored type values of the requested type, and the qualified id column with the
 * attribute the model names it by.
 *
 * @internal
 */
final readonly class MorphColumnsOf
{
    /**
     * @param list<string> $storedTypes
     */
    public function __construct(
        public Model $model,
        public string $typeColumn,
        public array $storedTypes,
        public string $idAttribute,
        public string $idColumn,
    ) {}

    /**
     * The table both columns are read from.
     */
    public function table(): string
    {
        $dot = \strrpos($this->idColumn, '.');

        return $dot === false ? $this->model->getTable() : \substr($this->idColumn, 0, $dot);
    }
}
