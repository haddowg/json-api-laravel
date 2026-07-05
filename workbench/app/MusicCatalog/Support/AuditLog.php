<?php

declare(strict_types=1);

namespace Workbench\App\MusicCatalog\Support;

/**
 * A tiny in-memory audit trail the cross-cutting
 * {@see \Workbench\App\MusicCatalog\Listeners\AuditLogSubscriber} appends to whenever a
 * write commits — the workbench's witness for an **after-commit** event listener (the
 * place a real app emits an audit record, a domain event, a cache bust, a webhook). The
 * Laravel twin of the Symfony example's `AuditLog` store.
 *
 * It is bound as a container **singleton** purely so the feature test can read the
 * recorded entries back; a production app would inject a real logger or message bus and
 * never expose the store. Each entry is a `"{action} {type}#{id}"` line (e.g.
 * `created playlists#…`, `deleted tracks#4`).
 */
final class AuditLog
{
    /** @var list<string> */
    private array $entries = [];

    public function record(string $action, string $type, string $id): void
    {
        $this->entries[] = \sprintf('%s %s#%s', $action, $type, $id);
    }

    /**
     * @return list<string>
     */
    public function entries(): array
    {
        return $this->entries;
    }

    public function clear(): void
    {
        $this->entries = [];
    }
}
