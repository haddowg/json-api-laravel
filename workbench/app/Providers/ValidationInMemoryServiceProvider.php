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
use Workbench\App\Validation\Checklist;
use Workbench\App\Validation\ChecklistResource;

/**
 * The **in-memory** half of the validation-conformance wiring: it registers the
 * {@see ArticleResource} explicitly (it lives outside the scanned `app/JsonApi` so it
 * never perturbs the music suites) and a writable, seeded {@see InMemoryDataProvider} /
 * {@see InMemoryDataPersister} pair sharing one store, so a write is immediately
 * readable and no database is touched — the witness half of the dual-provider bridge
 * contract. Uniqueness (`Rule::unique`) is inert on this provider (no table), the
 * documented Eloquent-only asymmetry.
 */
final class ValidationInMemoryServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        JsonApi::register([ArticleResource::class, ChecklistResource::class]);

        $articles = new InMemoryDataProvider('articles', $this->articles(), identify: self::identify(), assignId: self::assignId());
        JsonApi::provider($articles);
        JsonApi::persister(new InMemoryDataPersister('articles', $articles->store(), static fn(): Article => new Article()));

        $checklists = new InMemoryDataProvider('checklists', [
            '1' => new Checklist(id: '1', done: true, count: 3, ratio: 1.5, title: 'Pack', tags: ['a'], settings: ['k' => 'v'], note: 'Soon'),
        ], identify: self::identify(), assignId: self::assignId());
        JsonApi::provider($checklists);
        JsonApi::persister(new InMemoryDataPersister('checklists', $checklists->store(), static fn(): Checklist => new Checklist()));
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
