<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Fixtures\ThroughFilters;

use haddowg\JsonApi\Resource\AbstractResource;
use haddowg\JsonApi\Resource\Field\Id;
use haddowg\JsonApi\Resource\Field\Str;

/**
 * A template, served as `tpl-<key>` through {@see TemplateIdCodec}.
 */
final class TemplateResource extends AbstractResource
{
    public static string $type = 'templates';

    public function fields(): array
    {
        return [
            Id::make()->encodeUsing(new TemplateIdCodec()),
            Str::make('name'),
        ];
    }
}
