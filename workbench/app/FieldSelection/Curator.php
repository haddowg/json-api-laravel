<?php

declare(strict_types=1);

namespace Workbench\App\FieldSelection;

/**
 * The plain model behind the in-memory `curators` type.
 */
final class Curator
{
    public const array SEED_STATS = ['rating' => 5, 'reviews' => 12, 'secret' => 1];
    public const array SEED_OFFICE = ['city' => 'Paris', 'geo' => ['lat' => 48.8, 'lng' => 2.3]];
    public const array SEED_EXHIBITS = [['title' => 'Light', 'year' => 2001], ['title' => 'Sound', 'year' => 2004]];

    /**
     * @param array<string, mixed>|null $stats
     * @param array<string, mixed>|null $office
     * @param list<mixed>|null          $exhibits
     */
    public function __construct(
        public string $id = '',
        public string $name = '',
        public ?array $stats = null,
        public ?array $office = null,
        public ?array $exhibits = null,
    ) {}
}
