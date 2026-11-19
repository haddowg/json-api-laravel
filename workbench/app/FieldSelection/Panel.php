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

    /**
     * @param array<string, mixed>|null $values
     * @param array<string, mixed>|null $groups
     * @param array<string, mixed>|null $settings
     */
    public function __construct(
        public string $id = '',
        public string $name = '',
        public ?array $values = null,
        public ?array $groups = null,
        public ?array $settings = null,
        public ?Curator $curator = null,
    ) {}
}
