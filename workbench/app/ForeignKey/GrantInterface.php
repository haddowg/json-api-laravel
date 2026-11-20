<?php

declare(strict_types=1);

namespace Workbench\App\ForeignKey;

/**
 * A grant of secrets, a plain object in the in-memory wiring and a model in the Eloquent
 * one, both held by {@see FkGrantProvider} rather than a table. It keeps the ids each write
 * sent, and reading its write-only secrets throws, so a request that reads the current
 * members of that relation fails.
 */
interface GrantInterface
{
    public function grantId(): string;

    /**
     * @return list<string>
     */
    public function receivedIds(): array;

    /**
     * @param list<string> $ids
     */
    public function receive(array $ids): void;

    /**
     * The members an action's input holds for `$relation`, as the related models it was
     * given, or `null` when it was given something else, such as bare ids.
     *
     * @return list<object>|null
     */
    public function inputMembers(string $relation): ?array;
}
