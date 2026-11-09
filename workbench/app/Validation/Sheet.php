<?php

declare(strict_types=1);

namespace Workbench\App\Validation;

/**
 * The plain model behind the in-memory `sheets` type.
 */
final class Sheet
{
    public const array SEED_ROWS = [['Key' => 'RFP-1', 'Vendor' => 'Acme']];
    public const array SEED_LABELS = ['tone' => 'warm', 'size' => 'large'];
    public const array SEED_SETTINGS = ['theme' => 'dark', 'limits' => ['daily' => 10]];
    public const array SEED_METRICS = ['views' => 3];

    /**
     * @param list<mixed>|null          $rows
     * @param array<string, mixed>|null $labels
     * @param array<string, mixed>|null $settings
     * @param array<string, mixed>|null $metrics
     */
    public function __construct(
        public string $id = '',
        public string $name = '',
        public ?array $rows = null,
        public ?array $labels = null,
        public ?array $settings = null,
        public ?array $metrics = null,
    ) {}
}
