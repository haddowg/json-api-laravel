<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Fixtures\IdentifiedMorphMany;

use haddowg\JsonApi\Hydrator\Relationship\ToManyRelationship;
use haddowg\JsonApi\Resource\AbstractResource;
use haddowg\JsonApi\Resource\Field\Id;
use haddowg\JsonApi\Resource\Field\Mode;
use haddowg\JsonApi\Resource\Field\MorphToMany;
use haddowg\JsonApi\Resource\Field\Str;

/**
 * `companies` reads its pairs off the join rows with identifiedBy() and writes them back with
 * fillUsing(), honouring the relationship endpoint's mode.
 */
final class UserResource extends AbstractResource
{
    public static string $type = 'users';

    public function fields(): array
    {
        return [
            Id::make(),
            Str::make('name'),
            MorphToMany::make('companies', ['agencies', 'advertisers', 'publishers'])
                ->countable()
                ->identifiedBy(static fn(mixed $user): array => self::pairs($user))
                ->fillUsing(static function (mixed $user, mixed $linkage, array $data): mixed {
                    \assert($linkage instanceof ToManyRelationship);
                    $sent = [];
                    foreach ($linkage->resourceIdentifiers as $identifier) {
                        $sent[$identifier->type . ':' . $identifier->id] = [$identifier->type, (int) $identifier->id];
                    }

                    $mode = $data['mode'] ?? Mode::Replace;
                    $next = [];
                    foreach (self::pairs($user) as [$type, $id]) {
                        $named = isset($sent[$type . ':' . $id]);
                        if (!(($mode === Mode::Replace && !$named) || ($mode === Mode::Remove && $named))) {
                            $next[] = [$type, $id];
                        }
                        unset($sent[$type . ':' . $id]);
                    }
                    if ($mode !== Mode::Remove) {
                        \array_push($next, ...\array_values($sent));
                    }

                    if ($user instanceof UserRecord) {
                        $user->companies = $next;
                    } elseif ($user instanceof MtmUser) {
                        $user->userCompanies()->delete();
                        foreach ($next as [$type, $id]) {
                            $user->userCompanies()->create(['company_type' => $type, 'company_id' => $id]);
                        }
                        $user->unsetRelation('userCompanies');
                    }

                    return $user;
                }),
        ];
    }

    /**
     * @return list<array{0: string, 1: int}>
     */
    private static function pairs(mixed $user): array
    {
        if ($user instanceof UserRecord) {
            return $user->companies;
        }

        if (!$user instanceof MtmUser) {
            return [];
        }

        return \array_values($user->userCompanies->map(static fn(MtmUserCompany $row): array => [$row->company_type, $row->company_id])->all());
    }
}
