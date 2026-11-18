<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Fixtures\CursorSortServability;

use haddowg\JsonApi\Pagination\CursorPaginator;
use haddowg\JsonApi\Pagination\PaginatorInterface;
use haddowg\JsonApi\Resource\AbstractResource;
use haddowg\JsonApi\Resource\Field\Id;
use haddowg\JsonApiLaravel\Attribute\AsJsonApiResource;

#[AsJsonApiResource(readOnly: true)]
final class UnkeyableShelfResource extends AbstractResource
{
    public static string $type = 'unkeyableShelves';

    public function fields(): array
    {
        return [Id::make()];
    }

    public function sorts(): array
    {
        return [new UnkeyableSort()];
    }

    public function pagination(?PaginatorInterface $serverDefault): PaginatorInterface
    {
        return CursorPaginator::make();
    }
}
