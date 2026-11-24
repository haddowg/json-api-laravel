<?php

declare(strict_types=1);

namespace Workbench\App\RequiredRelation;

use haddowg\JsonApi\Resource\AbstractResource;
use haddowg\JsonApi\Resource\Field\Id;
use haddowg\JsonApi\Resource\Field\Str;

final class MemberResource extends AbstractResource
{
    public static string $type = 'members';

    public function fields(): array
    {
        return [
            Id::make(),
            Str::make('name'),
        ];
    }
}
