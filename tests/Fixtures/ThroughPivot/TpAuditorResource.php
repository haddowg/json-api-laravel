<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Fixtures\ThroughPivot;

use haddowg\JsonApi\Resource\AbstractResource;
use haddowg\JsonApi\Resource\Field\Id;
use haddowg\JsonApi\Resource\Field\Str;
use haddowg\JsonApiLaravel\Attribute\AsJsonApiResource;

/**
 * Auditors, whose collection only an admin may list, so a path reaching them is refused to
 * anyone else.
 */
#[AsJsonApiResource(readOnly: true, policy: TpAuditorPolicy::class)]
final class TpAuditorResource extends AbstractResource
{
    public static string $type = 'tpAuditors';

    public function fields(): array
    {
        return [Id::make(), Str::make('name')];
    }
}
