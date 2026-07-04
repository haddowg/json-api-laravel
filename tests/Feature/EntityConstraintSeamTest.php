<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Feature;

use haddowg\JsonApiLaravel\JsonApiServiceProvider;
use haddowg\JsonApiLaravel\Tests\Fixtures\EntitySeam\EntitySeamServiceProvider;
use Illuminate\Testing\TestResponse;
use Orchestra\Testbench\TestCase as Orchestra;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;

/**
 * Proves the retained post-hydration {@see \haddowg\JsonApiLaravel\Validation\EntityConstraintInterface}
 * seam (PLAN decision 6) end to end, together with the class-keyed extension-translator
 * path: the `notes` resource declares a custom {@see \haddowg\JsonApiLaravel\Tests\Fixtures\EntitySeam\UniqueNoteTitle}
 * constraint whose translator produces a store-scanning rule run against the HYDRATED
 * ENTITY (not the request document). A create/update whose resulting note collides on
 * title with another stored note is a `422` at `/data/attributes/title`, while a fresh
 * title — or a note re-sending its OWN title — is accepted.
 *
 * @internal
 */
final class EntityConstraintSeamTest extends Orchestra
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
            EntitySeamServiceProvider::class,
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
    }

    #[Test]
    #[Group('spec:crud')]
    public function creatingANoteWhoseHydratedTitleCollidesIsAPostHydration422(): void
    {
        $response = $this->writeJsonApi('POST', '/api/notes', [
            'data' => ['type' => 'notes', 'attributes' => ['title' => 'First Note']],
        ]);

        $response->assertStatus(422);
        $response->assertJsonPath('errors.0.status', '422');
        $response->assertJsonPath('errors.0.source.pointer', '/data/attributes/title');

        // Rejected before persist: still the two seeded notes.
        $this->readJsonApi('/api/notes')->assertJsonCount(2, 'data');
    }

    #[Test]
    #[Group('spec:crud')]
    public function creatingANoteWithAFreshTitleSucceeds(): void
    {
        $response = $this->writeJsonApi('POST', '/api/notes', [
            'data' => ['type' => 'notes', 'attributes' => ['title' => 'A Fresh Title']],
        ]);

        $response->assertStatus(201);
        $response->assertJsonPath('data.attributes.title', 'A Fresh Title');
    }

    #[Test]
    #[Group('spec:crud')]
    public function updatingANoteToAnotherNotesTitleIsAPostHydration422AndLeavesItUnchanged(): void
    {
        $response = $this->writeJsonApi('PATCH', '/api/notes/1', [
            'data' => ['type' => 'notes', 'id' => '1', 'attributes' => ['title' => 'Second Note']],
        ]);

        $response->assertStatus(422);
        $response->assertJsonPath('errors.0.source.pointer', '/data/attributes/title');

        // The post-hydration rejection rolled the store back: note 1 keeps its own title.
        $this->readJsonApi('/api/notes/1')
            ->assertOk()
            ->assertJsonPath('data.attributes.title', 'First Note');
    }

    #[Test]
    #[Group('spec:crud')]
    public function updatingANoteWithItsOwnTitleIsAccepted(): void
    {
        // Self-excluded by id: re-sending a note's own title does not collide with itself.
        $response = $this->writeJsonApi('PATCH', '/api/notes/1', [
            'data' => ['type' => 'notes', 'id' => '1', 'attributes' => ['title' => 'First Note']],
        ]);

        $response->assertStatus(200);
        $response->assertJsonPath('data.attributes.title', 'First Note');
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

    /**
     * @return TestResponse<\Symfony\Component\HttpFoundation\Response>
     */
    private function readJsonApi(string $uri): TestResponse
    {
        return $this->get($uri, ['Accept' => self::MEDIA_TYPE]);
    }
}
