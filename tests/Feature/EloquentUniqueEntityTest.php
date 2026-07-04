<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Feature;

use haddowg\JsonApiLaravel\JsonApiServiceProvider;
use Illuminate\Testing\TestResponse;
use Orchestra\Testbench\TestCase as Orchestra;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use Workbench\App\Models\Article;
use Workbench\App\Providers\ValidationEloquentServiceProvider;

/**
 * The `UniqueEntity` → pre-hydration `Rule::unique` witness (PLAN decision 6): the
 * `articles` resource declares `->constrain(new UniqueEntity(['slug']))` on `slug`, which
 * the {@see \haddowg\JsonApiLaravel\Validation\ResourceValidator} realises as a
 * `Rule::unique` on the Eloquent table BEFORE hydration — a duplicate is a `422` at
 * `/data/attributes/slug` before the persister runs. On update the current record is
 * excluded via `->ignore()`, so re-sending a row's own slug is accepted while colliding
 * with another row is rejected. This is Eloquent-only (a POPO has no table), so it is a
 * dedicated Eloquent feature test rather than a dual-provider conformance case.
 *
 * @internal
 */
#[CoversNothing]
final class EloquentUniqueEntityTest extends Orchestra
{
    private const string MEDIA_TYPE = 'application/vnd.api+json';

    /**
     * @param \Illuminate\Foundation\Application $app
     *
     * @return array<int, class-string>
     */
    protected function getPackageProviders($app): array
    {
        return [
            JsonApiServiceProvider::class,
            ValidationEloquentServiceProvider::class,
        ];
    }

    /**
     * @param \Illuminate\Foundation\Application $app
     */
    protected function defineEnvironment($app): void
    {
        /** @var \Illuminate\Contracts\Config\Repository $config */
        $config = $app['config'];
        $config->set('jsonapi.base_uri', 'http://localhost/api');
        $config->set('database.default', 'testing');
        $config->set('database.connections.testing', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
            'foreign_key_constraints' => false,
        ]);
    }

    protected function defineDatabaseMigrations(): void
    {
        $this->loadMigrationsFrom(\dirname(__DIR__, 2) . '/workbench/database/migrations');
    }

    protected function setUp(): void
    {
        parent::setUp();
        Article::query()->create(['id' => 1, 'title' => 'First', 'category' => 'guide', 'slug' => 'json-api-in-php']);
        Article::query()->create(['id' => 2, 'title' => 'Second', 'category' => 'news', 'slug' => 'second-article']);
    }

    #[Test]
    #[Group('spec:crud')]
    public function creatingWithADuplicateUniqueValueReturns422AtThatPointer(): void
    {
        $response = $this->writeJsonApi('POST', '/api/articles', [
            'data' => ['type' => 'articles', 'attributes' => [
                'title' => 'A fine title', 'category' => 'guide', 'slug' => 'json-api-in-php',
            ]],
        ]);

        $response->assertStatus(422);
        $response->assertJsonPath('errors.0.status', '422');
        self::assertSame('/data/attributes/slug', $response->json('errors.0.source.pointer'));
        $detail = $response->json('errors.0.detail');
        self::assertIsString($detail);
        self::assertNotSame('', $detail, 'a uniqueness violation carries a non-empty detail');
        // The duplicate aborted before persist: still only the two seeded rows.
        self::assertSame(2, Article::query()->count());
    }

    #[Test]
    #[Group('spec:crud')]
    public function creatingWithAFreshUniqueValueSucceeds(): void
    {
        $response = $this->writeJsonApi('POST', '/api/articles', [
            'data' => ['type' => 'articles', 'attributes' => [
                'title' => 'A fine title', 'category' => 'guide', 'slug' => 'a-brand-new-slug',
            ]],
        ]);

        $response->assertStatus(201);
        $response->assertJsonPath('data.attributes.slug', 'a-brand-new-slug');
        self::assertSame(3, Article::query()->count());
    }

    #[Test]
    #[Group('spec:crud')]
    public function updatingARowWithItsOwnUniqueValueIsAccepted(): void
    {
        // The current record is excluded from the uniqueness check (->ignore), so
        // re-sending a row's own slug does not collide with itself.
        $response = $this->writeJsonApi('PATCH', '/api/articles/1', [
            'data' => ['type' => 'articles', 'id' => '1', 'attributes' => [
                'slug' => 'json-api-in-php', 'title' => 'An Edited Title',
            ]],
        ]);

        $response->assertStatus(200);
        $response->assertJsonPath('data.attributes.title', 'An Edited Title');
    }

    #[Test]
    #[Group('spec:crud')]
    public function updatingARowToAnotherRowsUniqueValueReturns422(): void
    {
        $response = $this->writeJsonApi('PATCH', '/api/articles/1', [
            'data' => ['type' => 'articles', 'id' => '1', 'attributes' => ['slug' => 'second-article']],
        ]);

        $response->assertStatus(422);
        $response->assertJsonPath('errors.0.status', '422');
        self::assertSame('/data/attributes/slug', $response->json('errors.0.source.pointer'));
        $detail = $response->json('errors.0.detail');
        self::assertIsString($detail);
        self::assertNotSame('', $detail, 'a uniqueness violation carries a non-empty detail');
    }

    /**
     * @param array<string, mixed> $document
     *
     * @return TestResponse<\Symfony\Component\HttpFoundation\Response>
     */
    private function writeJsonApi(string $method, string $uri, array $document): TestResponse
    {
        return $this->json($method, $uri, $document, [
            'Accept' => self::MEDIA_TYPE,
            'CONTENT_TYPE' => self::MEDIA_TYPE,
        ]);
    }
}
