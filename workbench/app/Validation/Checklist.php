<?php

declare(strict_types=1);

namespace Workbench\App\Validation;

/**
 * The in-memory `checklists` domain object behind {@see ChecklistResource}.
 */
final class Checklist
{
    /**
     * @param list<mixed>|null          $tags
     * @param array<string, mixed>|null $settings
     * @param array<string, mixed>|null $options
     */
    public function __construct(
        public string $id = '',
        public ?bool $done = null,
        public ?int $count = null,
        public ?float $ratio = null,
        public ?string $title = null,
        public ?array $tags = null,
        public ?array $settings = null,
        public ?string $note = null,
        public ?array $options = null,
    ) {}
}
