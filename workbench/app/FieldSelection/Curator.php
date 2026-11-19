<?php

declare(strict_types=1);

namespace Workbench\App\FieldSelection;

/**
 * The plain model behind the in-memory `curators` type.
 */
final class Curator
{
    public const array SEED_STATS = ['rating' => 5, 'reviews' => 12, 'secret' => 1];

    /**
     * @param array<string, mixed>|null $stats
     */
    public function __construct(
        public string $id = '',
        public string $name = '',
        public ?array $stats = null,
    ) {}
}
