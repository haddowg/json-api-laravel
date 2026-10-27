<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Fixtures\Scopes;

use haddowg\JsonApi\Scope\GrantedScopesProviderInterface;
use haddowg\JsonApi\Scope\OperationScopeResolverInterface;
use haddowg\JsonApiLaravel\DataPersister\InMemoryDataPersister;
use haddowg\JsonApiLaravel\DataProvider\InMemoryDataProvider;
use haddowg\JsonApiLaravel\Facades\JsonApi;
use Illuminate\Support\ServiceProvider;
use Workbench\App\MusicCatalog\Security\BearerTokenScopes;

/**
 * Serves shelves, books and notes from memory, with scopes read off the music catalogue's
 * `user#scope` bearer token and resolved by {@see BookReadScopes}.
 */
final class ScopesServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        JsonApi::register([ShelfResource::class, BookResource::class, NoteResource::class]);

        $dune = new Book('1', 'Dune');
        JsonApi::provider(new InMemoryDataProvider('shelves', ['1' => new Shelf('1', 'Fiction', $dune)]));
        $books = new InMemoryDataProvider('books', ['1' => $dune]);
        JsonApi::provider($books);
        JsonApi::persister(new InMemoryDataPersister('books', $books->store(), static fn(): Book => new Book()));
        JsonApi::provider(new InMemoryDataProvider('notes', ['1' => new Book('1', 'Note')]));

        $this->app->singleton(BookReadScopes::class);
        $this->app->alias(BookReadScopes::class, OperationScopeResolverInterface::class);
        $this->app->singleton(GrantedScopesProviderInterface::class, BearerTokenScopes::class);
    }
}
