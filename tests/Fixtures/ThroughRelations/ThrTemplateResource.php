<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Fixtures\ThroughRelations;

use haddowg\JsonApi\Resource\AbstractResource;
use haddowg\JsonApi\Resource\Field\Id;
use haddowg\JsonApi\Resource\Field\Str;
use haddowg\JsonApiLaravel\Tests\Fixtures\ThroughFilters\TemplateIdCodec;

/**
 * Templates whose integer key is served as `tpl-<id>`.
 */
final class ThrTemplateResource extends AbstractResource
{
    public static string $type = 'thrTemplates';

    public function fields(): array
    {
        return [
            Id::make()->encodeUsing(new TemplateIdCodec()),
            Str::make('name'),
        ];
    }
}
