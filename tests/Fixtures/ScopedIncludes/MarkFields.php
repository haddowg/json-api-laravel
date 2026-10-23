<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Fixtures\ScopedIncludes;

use haddowg\JsonApi\Pagination\PaginatorInterface;
use haddowg\JsonApi\Resource\AbstractResource;
use haddowg\JsonApi\Resource\Field\Id;
use haddowg\JsonApi\Resource\Field\Str;

abstract class MarkFields extends AbstractResource
{
    use DiscriminatesHolding;

    public static string $type = 'marks';

    public function fields(): array
    {
        return [
            Id::make(),
            Str::make('label')->sortable(),
        ];
    }

    public function pagination(?PaginatorInterface $serverDefault): ?PaginatorInterface
    {
        return null;
    }
}
