<?php

declare(strict_types=1);

namespace Workbench\App\Domain;

/**
 * A campaign seeded into the in-memory provider for the sort-family cases, the twin of
 * the Eloquent {@see \Workbench\App\Models\SortCampaign}.
 */
final class SortCampaign
{
    /**
     * @param array<string, float> $values placeholder values keyed by placeholder name
     */
    public function __construct(
        public string $id = '',
        public string $name = '',
        public string $team = '',
        public array $values = [],
    ) {}

    public function placeholderValue(string $placeholder): ?float
    {
        return $this->values[$placeholder] ?? null;
    }
}
