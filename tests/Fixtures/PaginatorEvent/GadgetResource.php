<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Fixtures\PaginatorEvent;

use haddowg\JsonApi\Pagination\PagePaginator;
use haddowg\JsonApi\Pagination\PaginatorInterface;
use haddowg\JsonApi\Resource\AbstractResource;
use haddowg\JsonApi\Resource\Field\Id;
use haddowg\JsonApi\Resource\Field\Str;
use haddowg\JsonApi\Resource\Filter\Where;
use haddowg\JsonApiLaravel\Attribute\AsJsonApiResource;

/**
 * Its {@see pagination()} returns whatever a test puts in {@see $paginator}, so one type
 * serves offset, cursor, multi and fetch-all collections. The `slug` filter is singular.
 */
#[AsJsonApiResource(readOnly: true)]
final class GadgetResource extends AbstractResource
{
    public static string $type = 'gadgets';

    public static ?PaginatorInterface $paginator = null;

    public static function reset(): void
    {
        self::$paginator = PagePaginator::make();
    }

    public function fields(): array
    {
        return [
            Id::make(),
            Str::make('slug'),
        ];
    }

    public function filters(): array
    {
        return [
            Where::make('slug')->singular(),
        ];
    }

    public function pagination(?PaginatorInterface $serverDefault): ?PaginatorInterface
    {
        return self::$paginator;
    }
}
