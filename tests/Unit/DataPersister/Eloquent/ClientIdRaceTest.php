<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Unit\DataPersister\Eloquent;

use haddowg\JsonApi\Exception\ClientGeneratedIdAlreadyExists;
use haddowg\JsonApiLaravel\DataPersister\Eloquent\EloquentDataPersister;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Orchestra\Testbench\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Workbench\App\Models\Genre;

/**
 * A create whose client id another connection takes after the persister's lookup and
 * before its insert. Both connections share one SQLite file, so the other insert commits
 * on its own.
 */
#[CoversClass(EloquentDataPersister::class)]
final class ClientIdRaceTest extends TestCase
{
    private string $database = '';

    /**
     * @param \Illuminate\Foundation\Application $app
     */
    protected function defineEnvironment($app): void
    {
        $this->database = \tempnam(\sys_get_temp_dir(), 'race') ?: throw new \RuntimeException('No temporary file.');

        /** @var \Illuminate\Contracts\Config\Repository $config */
        $config = $app['config'];
        foreach (['testing', 'racer'] as $name) {
            $config->set('database.connections.' . $name, ['driver' => 'sqlite', 'database' => $this->database, 'prefix' => '']);
        }
        $config->set('database.default', 'testing');
    }

    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('genres', static function (Blueprint $table): void {
            $table->string('id')->primary();
            $table->string('name')->unique();
        });
    }

    protected function tearDown(): void
    {
        Genre::flushEventListeners();
        parent::tearDown();
        @\unlink($this->database);
    }

    #[Test]
    public function aClientIdTakenAfterTheLookupIs409(): void
    {
        Genre::creating(static function (Genre $genre): void {
            DB::connection('racer')->table('genres')->insert(['id' => $genre->getKey(), 'name' => 'Raced']);
        });

        $this->expectException(ClientGeneratedIdAlreadyExists::class);

        (new EloquentDataPersister(['genres' => Genre::class]))->create('genres', $this->genre('raced', 'Mine'));
    }

    #[Test]
    public function anotherUniqueColumnTakenAfterTheLookupStaysAViolation(): void
    {
        Genre::creating(static function (Genre $genre): void {
            DB::connection('racer')->table('genres')->insert(['id' => 'other', 'name' => $genre->getAttribute('name')]);
        });

        $this->expectException(UniqueConstraintViolationException::class);

        (new EloquentDataPersister(['genres' => Genre::class]))->create('genres', $this->genre('mine', 'Shared'));
    }

    private function genre(string $id, string $name): Genre
    {
        $genre = new Genre();
        $genre->setAttribute('id', $id);
        $genre->setAttribute('name', $name);

        return $genre;
    }
}
