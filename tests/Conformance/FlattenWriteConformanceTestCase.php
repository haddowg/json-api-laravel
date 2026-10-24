<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Conformance;

use haddowg\JsonApi\Atomic\AtomicExtension;
use haddowg\JsonApiLaravel\JsonApiServiceProvider;
use haddowg\JsonApiLaravel\Tests\Fixtures\Flatten\FlattenServiceProvider;
use Illuminate\Testing\TestResponse;
use Orchestra\Testbench\TestCase as Orchestra;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;

/**
 * Writes through `on()` attributes land on the related model and stay there: every test
 * re-fetches the related resource rather than trusting the write response, which renders the
 * object the write mutated in memory. Run against the in-memory witness
 * ({@see InMemoryFlattenWriteConformanceTest}) and Eloquent ({@see EloquentFlattenWriteConformanceTest}).
 *
 * Seed: country 1 "Wonderland"; authors 1 "Ada Lovelace" and 2 "Grace Hopper", both in country 1;
 * book 1 by and edited by author 1, book 2 by and edited by author 2, book 3 with neither.
 */
abstract class FlattenWriteConformanceTestCase extends Orchestra
{
    public const string MEDIA_TYPE = 'application/vnd.api+json';

    public const string ATOMIC_MEDIA_TYPE = 'application/vnd.api+json; ext="' . AtomicExtension::URI . '"';

    /**
     * `eloquent` or `memory`.
     */
    abstract protected function store(): string;

    /**
     * @param \Illuminate\Foundation\Application $app
     *
     * @return array<int, class-string>
     */
    protected function getPackageProviders($app): array
    {
        // Set here rather than in defineEnvironment(): the fixture provider reads it while
        // registering, which testbench runs before defineEnvironment().
        /** @var \Illuminate\Contracts\Config\Repository $config */
        $config = $app['config'];
        $config->set('flatten.store', $this->store());

        return [JsonApiServiceProvider::class, FlattenServiceProvider::class];
    }

    /**
     * @param \Illuminate\Foundation\Application $app
     */
    protected function defineEnvironment($app): void
    {
        /** @var \Illuminate\Contracts\Config\Repository $config */
        $config = $app['config'];
        $config->set('jsonapi.atomic_operations.enabled', true);
    }

    #[Test]
    #[Group('spec:crud-updating')]
    public function aFlattenedAttributeWriteMutatesTheRelatedModel(): void
    {
        $this->update('/api/books/1', ['type' => 'books', 'id' => '1', 'attributes' => ['authorName' => 'Ada King']])
            ->assertOk()
            ->assertJsonPath('data.attributes.authorName', 'Ada King');

        $this->read('/api/authors/1')->assertJsonPath('data.attributes.name', 'Ada King');
        $this->read('/api/books/1')->assertJsonPath('data.attributes.authorName', 'Ada King');
    }

    #[Test]
    #[Group('spec:crud-updating')]
    public function aMultiHopFlattenedWriteMutatesTheFinalRelatedModel(): void
    {
        $this->update('/api/books/1', ['type' => 'books', 'id' => '1', 'attributes' => ['authorCountry' => 'Oz']])
            ->assertOk()
            ->assertJsonPath('data.attributes.authorCountry', 'Oz');

        // The country is shared by both authors, so book 2 reads the new name too.
        $this->read('/api/countries/1')->assertJsonPath('data.attributes.name', 'Oz');
        $this->read('/api/books/2')->assertJsonPath('data.attributes.authorCountry', 'Oz');
        $this->read('/api/authors/1')->assertJsonPath('data.attributes.name', 'Ada Lovelace');
    }

    #[Test]
    #[Group('spec:crud-updating')]
    public function aFlattenedWriteAndAPlainAttributeWritePersistTogether(): void
    {
        $this->update('/api/books/1', ['type' => 'books', 'id' => '1', 'attributes' => ['title' => 'Notes', 'authorName' => 'Ada King', 'authorCountry' => 'Oz']])
            ->assertOk();

        $this->read('/api/books/1')
            ->assertJsonPath('data.attributes.title', 'Notes')
            ->assertJsonPath('data.attributes.authorName', 'Ada King')
            ->assertJsonPath('data.attributes.authorCountry', 'Oz');
    }

    #[Test]
    #[Group('spec:crud-updating')]
    public function aSameBodyUpdateWritesTheNewlyAssociatedRelatedModel(): void
    {
        $this->update('/api/books/1', [
            'type' => 'books',
            'id' => '1',
            'attributes' => ['editorName' => 'Renamed Editor'],
            'relationships' => ['editor' => ['data' => ['type' => 'authors', 'id' => '2']]],
        ])->assertOk();

        $this->read('/api/authors/2')->assertJsonPath('data.attributes.name', 'Renamed Editor');
        $this->read('/api/authors/1')->assertJsonPath('data.attributes.name', 'Ada Lovelace');
        $this->read('/api/books/1')->assertJsonPath('data.relationships.editor.data.id', '2');
    }

    #[Test]
    #[Group('spec:crud-creating')]
    public function aSameBodyCreateWritesTheAssociatedRelatedModel(): void
    {
        $response = $this->call('POST', '/api/books', [], [], [], $this->headers(self::MEDIA_TYPE), $this->encode([
            'data' => [
                'type' => 'books',
                'attributes' => ['title' => 'New Title', 'editorName' => 'Grace The Editor'],
                'relationships' => ['editor' => ['data' => ['type' => 'authors', 'id' => '2']]],
            ],
        ]));
        $response->assertCreated();

        $id = $response->json('data.id');
        self::assertIsString($id);
        $this->read('/api/authors/2')->assertJsonPath('data.attributes.name', 'Grace The Editor');
        $this->read('/api/books/' . $id)->assertJsonPath('data.attributes.editorName', 'Grace The Editor');
    }

    #[Test]
    #[Group('spec:crud-updating')]
    public function aFlattenedWriteOverANullRelatedModelIs422(): void
    {
        $this->update('/api/books/3', ['type' => 'books', 'id' => '3', 'attributes' => ['authorCountry' => 'Nowhere']])
            ->assertStatus(422)
            ->assertJsonPath('errors.0.code', 'RELATED_ATTRIBUTE_OWNER_MISSING')
            ->assertJsonPath('errors.0.source.pointer', '/data/attributes/authorCountry');
    }

    #[Test]
    #[Group('spec:atomic')]
    public function aFlattenedWriteInAnAtomicBatchPersists(): void
    {
        $this->atomic([
            ['op' => 'update', 'ref' => ['type' => 'books', 'id' => '1'], 'data' => ['type' => 'books', 'id' => '1', 'attributes' => ['authorName' => 'Ada King']]],
            ['op' => 'update', 'ref' => ['type' => 'books', 'id' => '2'], 'data' => ['type' => 'books', 'id' => '2', 'attributes' => ['authorCountry' => 'Oz']]],
        ])->assertOk();

        $this->read('/api/authors/1')->assertJsonPath('data.attributes.name', 'Ada King');
        $this->read('/api/countries/1')->assertJsonPath('data.attributes.name', 'Oz');
    }

    #[Test]
    #[Group('spec:atomic')]
    public function aFlattenedWriteRollsBackWithItsAtomicBatch(): void
    {
        $this->atomic([
            ['op' => 'update', 'ref' => ['type' => 'books', 'id' => '1'], 'data' => ['type' => 'books', 'id' => '1', 'attributes' => ['authorName' => 'Ada King', 'authorCountry' => 'Oz']]],
            ['op' => 'remove', 'ref' => ['type' => 'books', 'id' => '999']],
        ])->assertStatus(404);

        $this->read('/api/authors/1')->assertJsonPath('data.attributes.name', 'Ada Lovelace');
        $this->read('/api/countries/1')->assertJsonPath('data.attributes.name', 'Wonderland');
    }

    /**
     * @param array<string, mixed> $data
     *
     * @return TestResponse<\Symfony\Component\HttpFoundation\Response>
     */
    protected function update(string $uri, array $data): TestResponse
    {
        return $this->call('PATCH', $uri, [], [], [], $this->headers(self::MEDIA_TYPE), $this->encode(['data' => $data]));
    }

    /**
     * @return TestResponse<\Symfony\Component\HttpFoundation\Response>
     */
    protected function read(string $uri): TestResponse
    {
        return $this->get($uri, ['Accept' => self::MEDIA_TYPE])->assertOk();
    }

    /**
     * @param list<array<string, mixed>> $operations
     *
     * @return TestResponse<\Symfony\Component\HttpFoundation\Response>
     */
    protected function atomic(array $operations): TestResponse
    {
        return $this->call('POST', '/api/operations', [], [], [], $this->headers(self::ATOMIC_MEDIA_TYPE), $this->encode(['atomic:operations' => $operations]));
    }

    /**
     * @return array<string, string>
     */
    private function headers(string $mediaType): array
    {
        return ['CONTENT_TYPE' => $mediaType, 'HTTP_ACCEPT' => $mediaType];
    }

    /**
     * @param array<string, mixed> $document
     */
    private function encode(array $document): string
    {
        return \json_encode($document, \JSON_THROW_ON_ERROR);
    }
}
