<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Feature\PersisterExtension;

use haddowg\JsonApiLaravel\JsonApiServiceProvider;
use haddowg\JsonApiLaravel\Tests\Fixtures\PersisterExtension\Note;
use haddowg\JsonApiLaravel\Tests\Fixtures\PersisterExtension\PersisterExtensionServiceProvider;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Orchestra\Testbench\TestCase as Orchestra;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;

/**
 * An application extends the reference Eloquent persister to build new entities and to
 * replace delete with a service call, and keeps every other write it inherits.
 *
 * @internal
 */
#[CoversNothing]
final class EloquentPersisterExtensionTest extends Orchestra
{
    private const string MEDIA_TYPE = 'application/vnd.api+json';

    /**
     * @param \Illuminate\Foundation\Application $app
     *
     * @return array<int, class-string>
     */
    protected function getPackageProviders($app): array
    {
        return [JsonApiServiceProvider::class, PersisterExtensionServiceProvider::class];
    }

    /**
     * @param \Illuminate\Foundation\Application $app
     */
    protected function defineEnvironment($app): void
    {
        /** @var \Illuminate\Contracts\Config\Repository $config */
        $config = $app['config'];
        $config->set('database.default', 'testing');
        $config->set('database.connections.testing', ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']);
        $config->set('jsonapi.atomic_operations.enabled', true);
    }

    protected function defineDatabaseMigrations(): void
    {
        Schema::create('pe_notes', static function (Blueprint $table): void {
            $table->increments('id');
            $table->string('body');
            $table->string('status');
            $table->string('archived_at')->nullable();
        });

        DB::table('pe_notes')->insert(['id' => 1, 'body' => 'Buy milk', 'status' => 'published']);
    }

    #[Test]
    public function aCreateStartsFromTheEntityTheSubclassBuilds(): void
    {
        $this->json('POST', '/api/notes', ['data' => ['type' => 'notes', 'attributes' => ['body' => 'Call Ada']]], $this->headers())
            ->assertCreated()
            ->assertJsonPath('data.attributes.status', 'draft');
    }

    #[Test]
    public function aDeleteRunsTheSubclassesDelete(): void
    {
        $this->json('DELETE', '/api/notes/1', [], $this->headers())->assertNoContent();

        $note = Note::query()->findOrFail(1);
        self::assertSame('2026-01-01 00:00:00', $note->archived_at);
    }

    #[Test]
    public function theInheritedWritesStillRunInAnAtomicBatch(): void
    {
        $atomic = 'application/vnd.api+json; ext="https://jsonapi.org/ext/atomic"';
        $body = (string) \json_encode(['atomic:operations' => [
            ['op' => 'update', 'ref' => ['type' => 'notes', 'id' => '1'], 'data' => ['type' => 'notes', 'id' => '1', 'attributes' => ['body' => 'Buy oat milk']]],
            ['op' => 'remove', 'ref' => ['type' => 'notes', 'id' => '1']],
        ]]);

        $this->call('POST', '/api/operations', [], [], [], ['HTTP_ACCEPT' => $atomic, 'CONTENT_TYPE' => $atomic], $body)->assertSuccessful();

        $note = Note::query()->findOrFail(1);
        self::assertSame('Buy oat milk', $note->body);
        self::assertSame('2026-01-01 00:00:00', $note->archived_at);
    }

    /**
     * @return array<string, string>
     */
    private function headers(): array
    {
        return ['Accept' => self::MEDIA_TYPE, 'Content-Type' => self::MEDIA_TYPE];
    }
}
