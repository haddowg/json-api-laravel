<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Feature;

use haddowg\JsonApiLaravel\Discovery\Discovery;
use haddowg\JsonApiLaravel\Discovery\DiscoveryScanner;
use haddowg\JsonApiLaravel\JsonApiServiceProvider;
use haddowg\JsonApiLaravel\Tests\Fixtures\Overrides\MemoHydrator;
use haddowg\JsonApiLaravel\Tests\Fixtures\Overrides\NoteSerializer;
use haddowg\JsonApiLaravel\Tests\Fixtures\Overrides\OverridesServiceProvider;
use haddowg\JsonApiLaravel\Tests\Support\InteractsWithOpenApiDocument;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\CoversNothing;

/**
 * The per-resource serializer/hydrator override, end-to-end over HTTP (ADR 0014, the
 * Laravel twin of bundle ADR 0023). `notes` declares
 * `#[AsJsonApiResource(serializer: NoteSerializer::class)]`: a read renders through the
 * hand-written serializer — whose constructor takes a **contextually-bound scalar**
 * (`$stamp`), so the injected value appearing on the wire proves the override was
 * container-resolved, not plain-`new`ed — while a write still hydrates through the
 * resource's field inventory. `memos` declares the mirror-image
 * `#[AsJsonApiResource(hydrator: MemoHydrator::class)]`: a write fans one `title` member
 * out to `title` + a derived `slug` (joined with the container-bound separator), while a
 * read still renders field-driven.
 *
 * The last test covers the optimize path: the `jsonapi:optimize` snapshot carries the
 * override class-strings, so a cached (`route:cache`d) app assembles the same servers a
 * live scan does.
 *
 * @internal
 */
#[CoversNothing]
final class ResourceSerializerHydratorOverrideTest extends TestCase
{
    use InteractsWithOpenApiDocument;

    public function test_a_read_renders_through_the_container_resolved_serializer_override(): void
    {
        $response = $this->get('/api/notes/1', ['Accept' => self::MEDIA_TYPE]);

        $response->assertOk();
        $response->assertJsonPath('data.type', 'notes');
        $response->assertJsonPath('data.attributes.title', 'First note');
        // Neither `stamp` nor `meta.served_by` exists on the resource's field inventory;
        // both come from the override's container-bound constructor argument.
        $response->assertJsonPath('data.attributes.stamp', OverridesServiceProvider::STAMP);
        $response->assertJsonPath('data.meta.served_by', OverridesServiceProvider::STAMP);
    }

    public function test_a_write_to_the_serializer_overridden_type_still_hydrates_field_driven(): void
    {
        $response = $this->writeJsonApi('PATCH', '/api/notes/1', [
            'data' => ['type' => 'notes', 'id' => '1', 'attributes' => ['title' => 'Renamed note']],
        ]);

        // The resource's `title` field hydrated the write (the override carries no
        // hydration), and the response document rendered through the override again.
        $response->assertOk();
        $response->assertJsonPath('data.attributes.title', 'Renamed note');
        $response->assertJsonPath('data.attributes.stamp', OverridesServiceProvider::STAMP);
    }

    public function test_a_write_hydrates_through_the_container_resolved_hydrator_override(): void
    {
        $response = $this->writeJsonApi('PATCH', '/api/memos/1', [
            'data' => ['type' => 'memos', 'id' => '1', 'attributes' => ['title' => 'Board Meeting Notes']],
        ]);

        // The override's fan-out set the read-only `slug` from `title`, joined with the
        // container-bound separator — a field-driven hydration could do neither.
        $response->assertOk();
        $response->assertJsonPath('data.attributes.title', 'Board Meeting Notes');
        $response->assertJsonPath(
            'data.attributes.slug',
            \implode(OverridesServiceProvider::SLUG_SEPARATOR, ['board', 'meeting', 'notes']),
        );
    }

    public function test_a_read_of_the_hydrator_overridden_type_still_renders_field_driven(): void
    {
        $response = $this->get('/api/memos/1', ['Accept' => self::MEDIA_TYPE]);

        $response->assertOk();
        $response->assertJsonPath('data.attributes.title', 'First memo');
        $response->assertJsonPath('data.attributes.slug', 'first_memo');
        // No `stamp`, no `meta.served_by` — memos carry no serializer override.
        self::assertNull($response->json('data.attributes.stamp'));
        self::assertNull($response->json('data.meta'));
    }

    public function test_the_optimize_snapshot_carries_the_overrides(): void
    {
        $cacheFile = \sys_get_temp_dir() . '/jsonapi-discovery-' . \uniqid() . '.php';
        config(['jsonapi.discovery.cache' => $cacheFile]);

        try {
            $this->jsonApiArtisan('jsonapi:optimize')->assertExitCode(0);
            $this->assertFileExists($cacheFile);

            // A fresh Discovery reading only the snapshot (no scan paths) yields the same
            // override class-strings a live scan does — the cached server assembly is
            // behaviourally identical.
            $cached = new Discovery(new DiscoveryScanner(), [], [], $cacheFile);
            $byType = [];
            foreach ($cached->resources() as $descriptor) {
                $byType[$descriptor->type] = $descriptor;
            }

            self::assertSame(NoteSerializer::class, $byType['notes']->serializer);
            self::assertNull($byType['notes']->hydrator);
            self::assertSame(MemoHydrator::class, $byType['memos']->hydrator);
            self::assertNull($byType['memos']->serializer);
        } finally {
            if (\is_file($cacheFile)) {
                @\unlink($cacheFile);
            }
        }
    }

    /**
     * @param \Illuminate\Foundation\Application $app
     *
     * @return array<int, class-string>
     */
    protected function getPackageProviders($app): array
    {
        return [
            JsonApiServiceProvider::class,
            OverridesServiceProvider::class,
        ];
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
