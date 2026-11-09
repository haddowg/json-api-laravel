<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Feature\ComputedPivot;

use haddowg\JsonApiLaravel\Testing\SchemaConformanceTrait;
use haddowg\JsonApiLaravel\Testing\SchemaDocumentKind;
use Illuminate\Testing\TestResponse;
use Orchestra\Testbench\TestCase as Orchestra;

/**
 * Computed pivot fields render per member from the playlist and the track, on a join with no
 * pivot columns and beside a stored field, and a write that sends one ignores it. Crate 1
 * holds tracks 1 to 4.
 *
 * @internal
 */
abstract class ComputedPivotTestCase extends Orchestra
{
    use SchemaConformanceTrait;

    public const string MEDIA_TYPE = 'application/vnd.api+json';

    public function test_the_related_endpoint_renders_computed_pivot_fields_with_no_pivot_columns(): void
    {
        $response = $this->read('/api/crates/1/labelledTracks')->assertOk();

        self::assertSame(['label' => 'Best Of / Airbag', 'rank' => 10], $this->pivotOf($response, '1'));
        self::assertSame(['label' => 'Best Of / Everything In Its Right Place', 'rank' => 40], $this->pivotOf($response, '4'));
    }

    public function test_the_relationship_endpoint_renders_computed_pivot_fields_with_no_pivot_columns(): void
    {
        $response = $this->read('/api/crates/1/relationships/labelledTracks')->assertOk();

        self::assertSame(['label' => 'Best Of / Paranoid Android', 'rank' => 20], $this->pivotOf($response, '2'));
        $this->assertResponseMatchesGeneratedSchema($response, 'crates', SchemaDocumentKind::Relationship, 'labelledTracks');
    }

    public function test_a_computed_pivot_field_is_not_a_sort_key(): void
    {
        $this->read('/api/crates/1/labelledTracks?sort=label')->assertStatus(400);
    }

    public function test_a_write_ignores_a_computed_pivot_field_it_sends(): void
    {
        $response = $this->call('PATCH', '/api/crates/1/relationships/labelledTracks', [], [], [], [
            'HTTP_ACCEPT' => self::MEDIA_TYPE,
            'CONTENT_TYPE' => self::MEDIA_TYPE,
        ], (string) \json_encode(['data' => [
            ['type' => 'tracks', 'id' => '5', 'meta' => ['pivot' => ['label' => 'mine', 'rank' => 1]]],
        ]]));

        $response->assertOk();
        self::assertSame(['label' => 'Best Of / Idioteque', 'rank' => 50], $this->pivotOf($response, '5'));

        $read = $this->read('/api/crates/1/relationships/labelledTracks')->assertOk();
        self::assertSame(['5'], \array_column((array) $read->json('data'), 'id'));
    }

    /**
     * @param array<string, string> $headers
     *
     * @return TestResponse<\Symfony\Component\HttpFoundation\Response>
     */
    protected function read(string $uri, array $headers = []): TestResponse
    {
        return $this->get($uri, ['Accept' => self::MEDIA_TYPE, ...$headers]);
    }

    /**
     * The `meta.pivot` of the member with `$id`, or null when it renders none.
     *
     * @param TestResponse<\Symfony\Component\HttpFoundation\Response> $response
     *
     * @return array<array-key, mixed>|null
     */
    protected function pivotOf(TestResponse $response, string $id): ?array
    {
        foreach ((array) $response->json('data') as $member) {
            if (\is_array($member) && ($member['id'] ?? null) === $id) {
                $pivot = \data_get($member, 'meta.pivot');

                return \is_array($pivot) ? $pivot : null;
            }
        }

        self::fail('No member ' . $id . ' in the response.');
    }
}
