<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Feature\MusicCatalog;

use haddowg\JsonApi\Schema\Profile\FieldSelectionProfile;
use Illuminate\Testing\TestResponse;
use Orchestra\Testbench\TestCase as Orchestra;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Group;

/**
 * The Field Selection witness behind `docs/resources.md`: `AlbumResource`'s `credits`
 * declares selectable keys, so a table showing one column per role asks for just those
 * keys with `fields[albums]=title,credits.producer`, sends no profile, and gets a
 * response that advertises the Field Selection profile. OK Computer (album 1) seeds a
 * producer, an engineer and an artwork credit, and two editions whose format and year a
 * discography table selects through `editions.*.format`.
 *
 * @internal
 */
#[CoversNothing]
#[Group('spec:sparse-fieldsets')]
abstract class FieldSelectionTestCase extends Orchestra
{
    public const string MEDIA_TYPE = 'application/vnd.api+json';

    /**
     * @param \Illuminate\Foundation\Application $app
     */
    protected function defineEnvironment($app): void
    {
        /** @var \Illuminate\Contracts\Config\Repository $config */
        $config = $app['config'];
        $config->set('jsonapi.servers', [
            'default' => ['prefix' => 'api', 'middleware' => [], 'domain' => null],
            'admin' => ['prefix' => 'admin', 'middleware' => [], 'domain' => null],
        ]);
    }

    public function test_a_table_selects_one_column_per_credit_role(): void
    {
        $response = $this->fetch('/api/albums/1?fields[albums]=title,credits.producer,credits.artwork');

        $response->assertOk();
        self::assertSame(
            ['title' => 'OK Computer', 'credits' => ['producer' => 'Nigel Godrich', 'artwork' => 'Stanley Donwood']],
            $response->json('data.attributes'),
        );
        self::assertSame('application/vnd.api+json; profile="' . FieldSelectionProfile::URI . '"', $response->headers->get('Content-Type'));
    }

    public function test_a_discography_table_selects_the_same_keys_of_every_edition(): void
    {
        $response = $this->fetch('/api/albums/1?fields[albums]=title,editions.*.format,editions.*.year');

        $response->assertOk();
        self::assertSame(
            ['title' => 'OK Computer', 'editions' => [['format' => 'CD', 'year' => 1997], ['format' => 'Vinyl', 'year' => 2017]]],
            $response->json('data.attributes'),
        );
        self::assertSame('application/vnd.api+json; profile="' . FieldSelectionProfile::URI . '"', $response->headers->get('Content-Type'));
    }

    public function test_an_index_into_the_editions_is_a_400(): void
    {
        $response = $this->fetch('/api/albums/1?fields[albums]=editions.0.format');

        $response->assertStatus(400);
        self::assertSame('FIELDSET_MEMBER_UNRECOGNIZED', $response->json('errors.0.code'));
    }

    public function test_without_a_key_member_the_response_is_unchanged(): void
    {
        $response = $this->fetch('/api/albums/1?fields[albums]=title,credits');

        $response->assertOk();
        self::assertSame(['producer' => 'Nigel Godrich', 'engineer' => 'Nigel Godrich', 'artwork' => 'Stanley Donwood'], $response->json('data.attributes.credits'));
        self::assertSame(self::MEDIA_TYPE, $response->headers->get('Content-Type'));
    }

    public function test_a_role_outside_the_pattern_is_a_400(): void
    {
        $response = $this->fetch('/api/albums/1?fields[albums]=credits.Producer');

        $response->assertStatus(400);
        self::assertSame('FIELDSET_MEMBER_UNRECOGNIZED', $response->json('errors.0.code'));
    }

    /**
     * @return TestResponse<\Symfony\Component\HttpFoundation\Response>
     */
    private function fetch(string $uri): TestResponse
    {
        return $this->get($uri, ['Accept' => self::MEDIA_TYPE]);
    }
}
