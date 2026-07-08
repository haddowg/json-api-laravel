<?php

declare(strict_types=1);

namespace Workbench\App\SoftDelete;

use haddowg\JsonApi\Request\JsonApiRequestInterface;
use haddowg\JsonApi\Resource\AbstractResource;
use haddowg\JsonApi\Resource\Field\Id;
use haddowg\JsonApi\Resource\Field\Str;
use haddowg\JsonApi\Resource\Filter\Contains;
use haddowg\JsonApiLaravel\Attribute\AsJsonApiResource;
use haddowg\JsonApiLaravel\DataProvider\Eloquent\OnlyTrashed;
use haddowg\JsonApiLaravel\DataProvider\Eloquent\WithTrashed;

/**
 * The `documents` resource — the **first-class soft-delete** showcase (Model B). Opting in is
 * a single declaration:
 *
 * ```php
 * #[AsJsonApiResource(softDeletes: true)]
 * ```
 *
 * which synthesizes two actions for the type — `POST /documents/{id}/-actions/restore`
 * (`200`, gated by the `restore` ability → {@see \Workbench\App\SoftDelete\Policies\DocumentPolicy::restore()},
 * exposed as a `trashed()`-conditional link) and `POST /documents/{id}/-actions/force-delete`
 * (`204`, gated by `forceDelete`). The ordinary `DELETE /documents/{id}` stays a **recoverable
 * soft delete** (`204`): the reference persister calls `$model->delete()`, which the
 * {@see \Workbench\App\Models\Document} model (using Laravel's `SoftDeletes`) soft-deletes.
 *
 * The read-side conveniences are the author-declared building blocks the ecosystem also uses:
 *  - the `trashed` **meta flag** via a one-line {@see getMeta()} override — rendered wherever a
 *    document appears, including as an `included` resource;
 *  - the `withTrashed` / `onlyTrashed` collection **filters** ({@see WithTrashed}/{@see OnlyTrashed}),
 *    author-named so the client-facing `filter[...]` key is yours to choose.
 */
#[AsJsonApiResource(softDeletes: true)]
final class DocumentResource extends AbstractResource
{
    public static string $type = 'documents';

    public function fields(): array
    {
        return [
            Id::make(),
            Str::make('title')->required()->maxLength(200)->sortable(),
            Str::make('body')->nullable(),
        ];
    }

    public function filters(): array
    {
        return [
            Contains::make('title'),
            WithTrashed::make('withTrashed'),
            OnlyTrashed::make('onlyTrashed'),
        ];
    }

    /**
     * The read signal for a soft-deleted resource: a server-owned `meta.trashed` lifecycle
     * flag, present only while the document is trashed. A one-liner rather than framework
     * magic — it renders wherever the resource does (primary, related, included) because the
     * transformer calls `getMeta()` uniformly.
     *
     * @return array<string, mixed>
     */
    public function getMeta(mixed $object, JsonApiRequestInterface $request): array
    {
        return \is_object($object) && \method_exists($object, 'trashed') && $object->trashed()
            ? ['trashed' => true]
            : [];
    }
}
