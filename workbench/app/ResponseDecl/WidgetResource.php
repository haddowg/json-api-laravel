<?php

declare(strict_types=1);

namespace Workbench\App\ResponseDecl;

use haddowg\JsonApi\OpenApi\Metadata\Accepted;
use haddowg\JsonApi\OpenApi\Metadata\Created;
use haddowg\JsonApi\OpenApi\Metadata\MetaResult;
use haddowg\JsonApi\OpenApi\Metadata\NoContent;
use haddowg\JsonApi\OpenApi\Metadata\Ok;
use haddowg\JsonApi\OpenApi\Metadata\SeeOther;
use haddowg\JsonApi\Resource\AbstractResource;
use haddowg\JsonApi\Resource\Field\Id;
use haddowg\JsonApi\Resource\Field\Str;
use haddowg\JsonApiLaravel\Attribute\AsJsonApiResource;

/**
 * The write-side response-declaration witness: a `widgets` type that declares each CRUD
 * response explicitly via the atomic response objects — a create that may defer to a
 * `widget-jobs` job (`202`) or echo the created resource (`201`); an update that may return
 * the resource (`200`) or nothing (`204`); a delete that may `204` or return a meta-only
 * document (`200`); and a fetch-one that may `200` or `303` (async completion). Exercises the
 * full projection; witnessed by
 * {@see \haddowg\JsonApiLaravel\Tests\Feature\ResponseDeclarationProjectionTest}.
 */
#[AsJsonApiResource(
    create: [new Created(), new Accepted('widget-jobs')],
    update: [new Ok(), new NoContent()],
    delete: [new NoContent(), new MetaResult()],
    fetchOne: [new Ok(), new SeeOther()],
)]
final class WidgetResource extends AbstractResource
{
    public static string $type = 'widgets';

    public function fields(): array
    {
        return [
            Id::make(),
            Str::make('name'),
        ];
    }
}
