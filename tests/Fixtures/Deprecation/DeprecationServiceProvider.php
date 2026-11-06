<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Fixtures\Deprecation;

use haddowg\JsonApi\Resource\Field\Accessor;
use haddowg\JsonApiLaravel\DataPersister\InMemoryDataPersister;
use haddowg\JsonApiLaravel\DataProvider\InMemoryDataProvider;
use haddowg\JsonApiLaravel\Facades\JsonApi;
use Illuminate\Support\ServiceProvider;

/**
 * Registers the deprecation fixtures over seeded in-memory stores.
 *
 * @internal
 */
final class DeprecationServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        JsonApi::register([
            WriterResource::class,
            ArticleResource::class,
            ArchiveResource::class,
            VaultResource::class,
            PublishArticleAction::class,
            ShareArticleAction::class,
        ]);

        $writer = new Note('1', 'Ada');
        $identify = static function (object $item): string {
            $id = Accessor::get($item, 'id');

            return \is_scalar($id) ? (string) $id : '';
        };

        JsonApi::provider(new InMemoryDataProvider('writers', ['1' => $writer], identify: $identify));
        $articles = new InMemoryDataProvider('articles', [
            '1' => new Note('1', 'First', 'Old', $writer),
            '2' => new Note('2', 'Second', 'Older', $writer),
        ], identify: $identify);
        JsonApi::provider($articles);
        JsonApi::persister(new InMemoryDataPersister('articles', $articles->store(), static fn(): Note => new Note()));
        JsonApi::provider(new InMemoryDataProvider('archives', ['1' => new Note('1', 'Kept')], identify: $identify));
        JsonApi::provider(new InMemoryDataProvider('vaults', ['1' => new Note('1', 'Locked')], identify: $identify));
    }
}
