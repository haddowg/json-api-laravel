<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Fixtures\OperationErrors;

use haddowg\JsonApi\Resource\AbstractResource;
use haddowg\JsonApi\Resource\Field\Id;
use haddowg\JsonApi\Resource\Field\Str;
use haddowg\JsonApiLaravel\Attribute\AsJsonApiResource;

/**
 * Declares {@see LockerLocked} for its update alone.
 *
 * @internal
 */
#[AsJsonApiResource(operationErrors: ['update' => [LockerLocked::class]])]
final class LockerResource extends AbstractResource
{
    public static string $type = 'lockers';

    public function fields(): array
    {
        return [
            Id::make(),
            Str::make('name'),
        ];
    }
}
