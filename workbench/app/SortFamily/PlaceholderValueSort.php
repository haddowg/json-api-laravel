<?php

declare(strict_types=1);

namespace Workbench\App\SortFamily;

use haddowg\JsonApi\Resource\Sort\KeysetSortInterface;

/**
 * Orders campaigns by one placeholder's value, the sort the `value` family resolves
 * `value.<placeholder>` to. A campaign without the placeholder sorts as null.
 */
final readonly class PlaceholderValueSort implements KeysetSortInterface
{
    public function __construct(
        public string $key,
        public string $placeholder,
    ) {}

    public function key(): string
    {
        return $this->key;
    }

    public function keysetValue(mixed $row): mixed
    {
        return \is_object($row) && \method_exists($row, 'placeholderValue') ? $row->placeholderValue($this->placeholder) : null;
    }
}
