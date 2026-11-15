<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Fixtures\FillUsingLinkage;

use haddowg\JsonApi\Resource\AbstractResource;
use haddowg\JsonApi\Resource\Field\Id;
use haddowg\JsonApi\Resource\Field\Str;
use haddowg\JsonApiLaravel\Attribute\AsJsonApiResource;

#[AsJsonApiResource(readOnly: true)]
final class TemplateSettingResource extends AbstractResource
{
    public static string $type = 'template-settings';

    public function fields(): array
    {
        return [Id::make(), Str::make('name')];
    }
}
