<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Fixtures\ReadOnly;

use haddowg\JsonApi\Resource\AbstractResource;
use haddowg\JsonApi\Resource\Field\Id;
use haddowg\JsonApi\Resource\Field\Str;
use haddowg\JsonApiLaravel\Attribute\AsJsonApiResource;

/**
 * A **read-only** type with NO `policy:` and no ability overrides, and no Gate policy
 * registered for it — the inertness witness (PLAN decision 7). Its persister-less list
 * subject is null and no policy is declared, so the authorizer stays inert and the
 * collection is served ungated. Backed by the same {@see Chart} POPOs as {@see ChartResource}.
 */
#[AsJsonApiResource(readOnly: true)]
final class LabelResource extends AbstractResource
{
    public static string $type = 'labels';

    public function fields(): array
    {
        return [
            Id::make(),
            Str::make('title'),
        ];
    }
}
