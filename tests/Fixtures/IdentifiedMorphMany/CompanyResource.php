<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Fixtures\IdentifiedMorphMany;

use haddowg\JsonApi\Resource\AbstractResource;
use haddowg\JsonApi\Resource\Field\Id;
use haddowg\JsonApi\Resource\Field\Str;

/**
 * A company type. In memory the three types share one record class, so each renders as the
 * type its pair names.
 */
abstract class CompanyResource extends AbstractResource
{
    public function fields(): array
    {
        return [Id::make(), Str::make('name'), Str::make('code')];
    }
}
