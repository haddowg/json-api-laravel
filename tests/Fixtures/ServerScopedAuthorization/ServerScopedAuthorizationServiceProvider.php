<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Fixtures\ServerScopedAuthorization;

use haddowg\JsonApi\Serializer\RelationshipLoadStateInterface;
use haddowg\JsonApiLaravel\DataPersister\Eloquent\EloquentDataPersister;
use haddowg\JsonApiLaravel\DataPersister\InMemoryDataPersister;
use haddowg\JsonApiLaravel\DataProvider\Eloquent\EloquentDataProvider;
use haddowg\JsonApiLaravel\DataProvider\Eloquent\EloquentRelationshipLoadState;
use haddowg\JsonApiLaravel\DataProvider\InMemoryDataProvider;
use haddowg\JsonApiLaravel\Facades\JsonApi;
use Illuminate\Support\ServiceProvider;

/**
 * Exposes `documents` and `notes` on both the `default` and `admin` servers through a
 * different resource class on each, and `labels` on both through one class, over the store named by `ssa.store`: `eloquent` or
 * `memory`. The `admin` classes register last, so a type keyed config would hand their
 * declarations to `default` too.
 *
 * Seed: document 1 "Handbook" holds notes 1 and 2; label 1 "Draft".
 *
 * @internal
 */
final class ServerScopedAuthorizationServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        JsonApi::register([
            DocumentResource::class, NoteResource::class, LabelResource::class, CountDocumentsAction::class,
            AdminDocumentResource::class, AdminNoteResource::class, AdminCountDocumentsAction::class,
        ]);

        if (config('ssa.store') === 'memory') {
            $notes = ['1' => new NoteRecord('1', 'First'), '2' => new NoteRecord('2', 'Second')];
            $documents = ['1' => new DocumentRecord('1', 'Handbook', \array_values($notes), \array_values($notes))];

            $documentProvider = new InMemoryDataProvider('documents', $documents, identify: static fn(object $document): string => $document instanceof DocumentRecord ? $document->id : '');
            $noteProvider = new InMemoryDataProvider('notes', $notes, identify: static fn(object $note): string => $note instanceof NoteRecord ? $note->id : '');
            JsonApi::provider($documentProvider);
            JsonApi::provider($noteProvider);
            JsonApi::persister(new InMemoryDataPersister('documents', $documentProvider->store(), static fn(): DocumentRecord => new DocumentRecord()));
            JsonApi::persister(new InMemoryDataPersister('notes', $noteProvider->store(), static fn(): NoteRecord => new NoteRecord()));
            JsonApi::provider(new InMemoryDataProvider('labels', ['1' => new LabelRecord('1', 'Draft')], identify: static fn(object $label): string => $label instanceof LabelRecord ? $label->id : ''));

            return;
        }

        $models = ['documents' => Document::class, 'notes' => Note::class, 'labels' => Label::class];
        JsonApi::provider(new EloquentDataProvider($models), priority: -128);
        JsonApi::persister(new EloquentDataPersister($models), priority: -128);
        $this->app->singleton(RelationshipLoadStateInterface::class, EloquentRelationshipLoadState::class);
    }
}
