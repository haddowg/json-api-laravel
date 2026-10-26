<?php

declare(strict_types=1);

namespace Workbench\App\Examples;

use haddowg\JsonApi\Pagination\PagePaginator;
use haddowg\JsonApi\Pagination\PaginatorInterface;
use haddowg\JsonApi\Resource\AbstractResource;
use haddowg\JsonApi\Resource\Field\HasMany;
use haddowg\JsonApi\Resource\Field\Id;
use haddowg\JsonApi\Resource\Field\Str;
use haddowg\JsonApiLaravel\Attribute\AsJsonApiResource;

/**
 * Both the vendors collection and the `widgets` relation count on every request, so their
 * collection examples show the total without a `withCount` request.
 */
#[AsJsonApiResource(readOnly: true)]
final class VendorResource extends AbstractResource
{
    public static string $type = 'vendors';

    public function fields(): array
    {
        return [
            Id::make()->example('3'),
            Str::make('name')->example('Acme'),
            HasMany::make('widgets', 'widgets')->paginate(PagePaginator::make()->withCount()),
        ];
    }

    public function pagination(?PaginatorInterface $serverDefault): PaginatorInterface
    {
        return PagePaginator::make()->withCount();
    }
}
