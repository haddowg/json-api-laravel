<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Fixtures\ThroughAccess;

use haddowg\JsonApi\Resource\AbstractResource;
use haddowg\JsonApi\Resource\Field\Id;
use haddowg\JsonApi\Resource\Field\Str;
use haddowg\JsonApiLaravel\Attribute\AsJsonApiResource;

/**
 * Ledgers, ungated: the relations to them carry the permissions.
 */
#[AsJsonApiResource(readOnly: true)]
final class LedgerResource extends AbstractResource
{
    public static string $type = 'ledgers';

    public function fields(): array
    {
        return [
            Id::make(),
            Str::make('code'),
        ];
    }
}
