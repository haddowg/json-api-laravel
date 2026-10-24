<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Fixtures\UriFieldName;

use haddowg\JsonApi\Resource\Field\Accessor;
use haddowg\JsonApiLaravel\DataPersister\Eloquent\EloquentDataPersister;
use haddowg\JsonApiLaravel\DataPersister\InMemoryDataPersister;
use haddowg\JsonApiLaravel\DataProvider\Eloquent\EloquentDataProvider;
use haddowg\JsonApiLaravel\DataProvider\InMemoryDataProvider;
use haddowg\JsonApiLaravel\Facades\JsonApi;
use Illuminate\Support\ServiceProvider;

/**
 * Wires the articles/comments/authors fixture over Eloquent, or over the in-memory witness
 * when `uri_field_name.store` is `memory`. Both start from the same data: article 1 by
 * author 1 with comments 1 and 2, and a second author.
 */
final class UriFieldNameServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        JsonApi::register([ArticleResource::class, AuthorResource::class, CommentResource::class]);

        if (config('uri_field_name.store') === 'memory') {
            $this->registerInMemory();

            return;
        }

        $models = ['articles' => Article::class, 'authors' => Author::class, 'comments' => Comment::class];
        JsonApi::provider(new EloquentDataProvider($models), priority: -128);
        JsonApi::persister(new EloquentDataPersister($models), priority: -128);
    }

    private function registerInMemory(): void
    {
        $authors = ['1' => new AuthorRecord('1', 'Ada'), '2' => new AuthorRecord('2', 'Grace')];
        $comments = ['1' => new CommentRecord('1', 'First'), '2' => new CommentRecord('2', 'Second')];
        $articles = ['1' => new ArticleRecord('1', 'Hello', [$comments['1'], $comments['2']], $authors['1'])];

        $identify = static function (object $item): string {
            $id = Accessor::get($item, 'id');

            return \is_scalar($id) ? (string) $id : '';
        };
        $articleProvider = new InMemoryDataProvider('articles', $articles, identify: $identify);
        $authorProvider = new InMemoryDataProvider('authors', $authors, identify: $identify);
        $commentProvider = new InMemoryDataProvider('comments', $comments, identify: $identify);

        $resolve = static fn(string $type, string $id): ?object => match ($type) {
            'authors' => $authorProvider->store()->find($id),
            'comments' => $commentProvider->store()->find($id),
            default => null,
        };

        JsonApi::provider($articleProvider);
        JsonApi::provider($authorProvider);
        JsonApi::provider($commentProvider);
        JsonApi::persister(new InMemoryDataPersister('articles', $articleProvider->store(), static fn(): ArticleRecord => new ArticleRecord(), $resolve));
    }
}
