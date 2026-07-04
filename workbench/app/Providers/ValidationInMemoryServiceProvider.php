<?php

declare(strict_types=1);

namespace Workbench\App\Providers;

use haddowg\JsonApi\Resource\Field\Accessor;
use haddowg\JsonApiLaravel\DataPersister\InMemoryDataPersister;
use haddowg\JsonApiLaravel\DataProvider\InMemoryDataProvider;
use haddowg\JsonApiLaravel\Facades\JsonApi;
use Illuminate\Support\ServiceProvider;
use Workbench\App\Validation\Article;
use Workbench\App\Validation\ArticleResource;

/**
 * The **in-memory** half of the validation-conformance wiring: it registers the
 * {@see ArticleResource} explicitly (it lives outside the scanned `app/JsonApi` so it
 * never perturbs the music suites) and a writable, seeded {@see InMemoryDataProvider} /
 * {@see InMemoryDataPersister} pair sharing one store, so a write is immediately
 * readable and no database is touched — the witness half of the dual-provider bridge
 * contract. Uniqueness (`Rule::unique`) is inert on this provider (no table), the
 * documented Eloquent-only asymmetry (PLAN decision 6).
 */
final class ValidationInMemoryServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        JsonApi::register([ArticleResource::class]);

        $articles = new InMemoryDataProvider('articles', $this->articles(), identify: self::identify(), assignId: self::assignId());
        JsonApi::provider($articles);
        JsonApi::persister(new InMemoryDataPersister('articles', $articles->store(), static fn(): Article => new Article()));
    }

    /**
     * @return array<int|string, Article>
     */
    private function articles(): array
    {
        return [
            '1' => new Article(id: '1', title: 'JSON:API in PHP', category: 'guide', slug: 'json-api-in-php'),
            '2' => new Article(id: '2', title: 'Second Article', category: 'news', slug: 'second-article'),
        ];
    }

    private static function identify(): \Closure
    {
        return static function (object $item): string {
            $id = Accessor::get($item, 'id');

            return \is_scalar($id) ? (string) $id : '';
        };
    }

    private static function assignId(): \Closure
    {
        return static function (object $item, string $id): void {
            Accessor::set($item, 'id', $id);
        };
    }
}
