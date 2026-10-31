<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Fixtures\RelationshipEntityConstraints;

use haddowg\JsonApi\Resource\AbstractResource;
use haddowg\JsonApi\Resource\Field\BelongsTo;
use haddowg\JsonApi\Resource\Field\BelongsToMany;
use haddowg\JsonApi\Resource\Field\Id;
use haddowg\JsonApi\Resource\Field\Str;
use haddowg\JsonApiLaravel\Attribute\AsJsonApiResource;

/**
 * An integration user needs a primary company and one or two companies. The name
 * carries an attribute constraint that some stored users break, which a
 * relationship write must not re-check.
 */
#[AsJsonApiResource]
final class IntegrationUserResource extends AbstractResource
{
    public static string $type = 'integration-users';

    public function fields(): array
    {
        return [
            Id::make(),
            Str::make('name')->maxLength(8),
            BelongsTo::make('primaryCompany', 'companies')->constrain(new EntityCheck(
                static fn(object $user): ?string => self::primaryCompanyOf($user) === null ? 'An integration user needs a primary company.' : null,
            )),
            BelongsToMany::make('companies', 'companies')->constrain(new EntityCheck(
                static fn(object $user): ?string => match (true) {
                    self::companyCount($user) === 0 => 'An integration user needs at least one company.',
                    self::companyCount($user) > 2 => 'An integration user may have at most two companies.',
                    default => null,
                },
            )),
        ];
    }

    private static function primaryCompanyOf(object $user): ?object
    {
        return match (true) {
            $user instanceof IntegrationUser => $user->primaryCompany,
            $user instanceof Domain => $user->primaryCompany,
            default => null,
        };
    }

    private static function companyCount(object $user): int
    {
        return match (true) {
            $user instanceof IntegrationUser => $user->companies()->count(),
            $user instanceof Domain => \count($user->companies),
            default => 0,
        };
    }
}
