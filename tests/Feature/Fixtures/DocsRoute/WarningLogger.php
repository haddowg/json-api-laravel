<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Feature\Fixtures\DocsRoute;

use Psr\Log\AbstractLogger;

/**
 * Keeps every warning it's given, so a test can assert what the optimize step logged.
 *
 * @internal
 */
final class WarningLogger extends AbstractLogger
{
    /** @var list<string> */
    public array $warnings = [];

    public function log($level, string|\Stringable $message, array $context = []): void
    {
        if ($level === 'warning') {
            $this->warnings[] = (string) $message;
        }
    }

    /**
     * The warnings about a document no route serves.
     *
     * @return list<string>
     */
    public function routeWarnings(): array
    {
        return \array_values(\array_filter($this->warnings, static fn(string $warning): bool => \str_contains($warning, 'No route serves')));
    }
}
