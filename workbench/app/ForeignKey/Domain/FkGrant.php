<?php

declare(strict_types=1);

namespace Workbench\App\ForeignKey\Domain;

use Workbench\App\ForeignKey\GrantInterface;

/**
 * The in-memory grant.
 */
final class FkGrant implements GrantInterface
{
    /**
     * The related models each relation was given, keyed by relation.
     *
     * @var array<string, list<object>|null>
     */
    private array $members = [];

    /**
     * @param list<string> $received
     * @param list<string> $lockedSecrets
     */
    public function __construct(
        public string $id = '',
        public array $received = [],
        public array $lockedSecrets = [],
    ) {}

    public function getSecrets(): never
    {
        throw new \LogicException('The current members of a grant\'s write-only secrets were read.');
    }

    /**
     * @param list<mixed> $secrets
     */
    public function setSecrets(array $secrets): void
    {
        $this->members['secrets'] = self::models($secrets);
        $this->received = self::ids($secrets);
    }

    /**
     * @param list<mixed> $secrets
     */
    public function setLockedSecrets(array $secrets): void
    {
        $this->members['lockedSecrets'] = self::models($secrets);
        $this->lockedSecrets = self::ids($secrets);
    }

    public function grantId(): string
    {
        return $this->id;
    }

    public function receivedIds(): array
    {
        return $this->received;
    }

    public function receive(array $ids): void
    {
        $this->received = $ids;
    }

    public function inputMembers(string $relation): ?array
    {
        return $this->members[$relation] ?? null;
    }

    /**
     * @param list<mixed> $members
     *
     * @return list<object>|null
     */
    private static function models(array $members): ?array
    {
        $models = \array_values(\array_filter($members, \is_object(...)));

        return \count($models) === \count($members) ? $models : null;
    }

    /**
     * @param list<mixed> $members
     *
     * @return list<string>
     */
    private static function ids(array $members): array
    {
        return \array_map(static fn(mixed $member): string => match (true) {
            $member instanceof FkSecret => $member->id,
            \is_scalar($member) => (string) $member,
            default => '',
        }, $members);
    }
}
