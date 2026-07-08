<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\DataProvider;

/**
 * The segregated trashed-inclusive read capability a {@see DataProviderInterface} may also
 * implement (the reference {@see \haddowg\JsonApiLaravel\DataProvider\Eloquent\EloquentDataProvider}
 * does): {@see fetchOneWithTrashed()} resolves a single entity **including** soft-deleted
 * rows, which the ordinary {@see DataProviderInterface::fetchOne()} excludes.
 *
 * It is consulted only for the package's synthesized soft-delete actions (their descriptor
 * carries {@see \haddowg\JsonApiLaravel\Action\ActionDescriptor::$resolvesTrashed}), so a
 * `restore` / `force-delete` action can resolve its trashed `{id}` target while every
 * ordinary endpoint (`GET`/`PATCH`/`DELETE`) keeps the strict, trashed-excluding resolution
 * — a trashed id still `404`s there.
 */
interface FetchesTrashed
{
    /**
     * The single resource of `$type` with `$id` **including** soft-deleted rows, or `null`
     * when none exists — the trashed-inclusive twin of {@see DataProviderInterface::fetchOne()}.
     */
    public function fetchOneWithTrashed(string $type, string $id): ?object;
}
