<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Fixtures\ReadOnly;

use haddowg\JsonApi\Resource\AbstractResource;
use haddowg\JsonApi\Resource\Field\Id;
use haddowg\JsonApi\Resource\Field\Str;
use haddowg\JsonApiLaravel\Attribute\AsJsonApiResource;

/**
 * A **read-only** type with a declared deny-all `policy:` and NO persister — the fixture
 * for the persister-less authorization case (findings: declared-policy fail-open on a
 * null list subject). `viewAny`/`view` must still be enforced against the resource-class
 * token even though the collection mints no list instance.
 */
#[AsJsonApiResource(readOnly: true, policy: DenyReadPolicy::class)]
final class ChartResource extends AbstractResource
{
    public static string $type = 'charts';

    public function fields(): array
    {
        return [
            Id::make(),
            Str::make('title'),
        ];
    }
}
