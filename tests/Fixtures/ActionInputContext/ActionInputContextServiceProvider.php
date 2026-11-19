<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Fixtures\ActionInputContext;

use haddowg\JsonApiLaravel\DataProvider\InMemoryDataProvider;
use haddowg\JsonApiLaravel\Facades\JsonApi;
use Illuminate\Support\ServiceProvider;

/**
 * Wires the `contextNotes` action over an empty in-memory store.
 *
 * @internal
 */
final class ActionInputContextServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        JsonApi::register([ContextNoteResource::class, RecordContextNoteAction::class]);
        JsonApi::provider(new InMemoryDataProvider('contextNotes', [], identify: static fn(object $note): string => \spl_object_hash($note)));
    }
}
