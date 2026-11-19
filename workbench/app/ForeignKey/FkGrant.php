<?php

declare(strict_types=1);

namespace Workbench\App\ForeignKey;

/**
 * A grant of secrets, held by {@see FkGrantProvider} rather than a model. It keeps the ids
 * each write sent in {@see $received}, and reading its secrets throws, so a request that
 * reads the current members of the write-only relation fails.
 */
final class FkGrant
{
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
     * @param list<string> $ids
     */
    public function setSecrets(array $ids): void
    {
        $this->received = $ids;
    }
}
