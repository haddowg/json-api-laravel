<?php

declare(strict_types=1);

namespace Workbench\App\ForeignKey\Models;

use Illuminate\Database\Eloquent\Model;
use Workbench\App\ForeignKey\GrantInterface;

/**
 * The Eloquent grant. It has no table: {@see \Workbench\App\ForeignKey\FkGrantProvider}
 * holds it, and an action's input holds the secrets it names as loaded relations.
 *
 * @property string       $id
 * @property list<string> $received
 * @property list<string> $lockedSecrets
 */
final class FkGrant extends Model implements GrantInterface
{
    public $timestamps = false;

    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = [];

    /**
     * @param list<string> $lockedSecrets
     */
    public static function seeded(string $id = '', array $lockedSecrets = []): self
    {
        $grant = new self();
        $grant->setRawAttributes(['id' => $id, 'received' => [], 'lockedSecrets' => $lockedSecrets]);

        return $grant;
    }

    public function getSecretsAttribute(): never
    {
        throw new \LogicException('The current members of a grant\'s write-only secrets were read.');
    }

    public function grantId(): string
    {
        $id = $this->getAttribute('id');

        return \is_scalar($id) ? (string) $id : '';
    }

    public function receivedIds(): array
    {
        /** @var list<string> */
        return $this->getAttribute('received') ?? [];
    }

    public function receive(array $ids): void
    {
        $this->setAttribute('received', $ids);
    }

    public function inputMembers(string $relation): ?array
    {
        if (!$this->relationLoaded($relation)) {
            return null;
        }
        $members = $this->getRelation($relation);

        return $members instanceof \Illuminate\Database\Eloquent\Collection ? \array_values($members->all()) : null;
    }
}
