<?php

declare(strict_types=1);

namespace Workbench\App\FieldSelection;

/**
 * The plain model behind the in-memory `panels` type, and the seed both providers share.
 */
final class Panel
{
    public const array SEED_VALUES = ['cap' => '10', 'countries' => 'GB,FR', 'region' => 'EU'];
    public const array SEED_GROUPS = ['rows' => [['a' => 1], ['a' => 2]], 'extra' => [['a' => 3]]];
    public const array SEED_SETTINGS = ['theme' => 'dark'];
    public const array SEED_ADDRESS = ['street' => '1 High Street', 'postcode' => 'AB1 2CD', 'geo' => ['lat' => 51.5, 'lng' => -0.1, 'accuracy' => 'rooftop'], 'history' => [['street' => '9 Low Road']]];
    public const array SEED_ROWS = [
        ['label' => 'A', 'size' => ['w' => 1, 'h' => 2], 'cells' => [['v' => 'x'], ['v' => 'y']], 'note' => 'first'],
        ['label' => 'B', 'size' => ['w' => 3, 'h' => 4], 'cells' => []],
    ];
    public const array SEED_TAGS = ['red', 'blue'];

    /**
     * @param array<string, mixed>|null $values
     * @param array<string, mixed>|null $groups
     * @param array<string, mixed>|null $settings
     * @param array<string, mixed>|null $address
     * @param list<mixed>|null          $rows
     * @param list<mixed>|null          $archive
     * @param list<string>|null         $tags
     */
    public function __construct(
        public string $id = '',
        public string $name = '',
        public ?array $values = null,
        public ?array $groups = null,
        public ?array $settings = null,
        public ?Curator $curator = null,
        public ?array $address = null,
        public ?array $rows = null,
        public ?array $archive = null,
        public ?array $tags = null,
    ) {}
}
