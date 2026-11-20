<?php

declare(strict_types=1);

namespace Workbench\App\ForeignKey;

use Illuminate\Database\Eloquent\Model;

/**
 * The ids of the secrets an action's input holds for a relation, which must be the related
 * models a write of the type holds.
 */
final class GrantInputIds
{
    /**
     * @return list<string>
     */
    public static function of(GrantInterface $input, string $relation): array
    {
        $members = $input->inputMembers($relation)
            ?? throw new \LogicException(\sprintf('The input\'s "%s" holds no related models.', $relation));

        return \array_map(static fn(object $secret): string => match (true) {
            $secret instanceof Model => \is_scalar($key = $secret->getKey()) ? (string) $key : '',
            $secret instanceof Domain\FkSecret => $secret->id,
            default => throw new \LogicException('The input holds something other than a secret.'),
        }, $members);
    }
}
