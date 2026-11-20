<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Feature;

use haddowg\JsonApi\Schema\Profile\FieldSelectionProfile;
use haddowg\JsonApiLaravel\JsonApiServiceProvider;
use haddowg\JsonApiLaravel\Tests\Fixtures\KeySelectionOverrides\EaselSerializer;
use haddowg\JsonApiLaravel\Tests\Fixtures\KeySelectionOverrides\KeySelectionOverridesServiceProvider;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Group;

/**
 * A type whose resource declares selectable keys but renders through a serializer
 * override is narrowed by the resource's fields, as its OpenAPI document says. `boards`
 * renders every attribute whole; `easels` implements core's `SelectsAttributeKeysInterface`
 * and records the selection it receives.
 *
 * @internal
 */
#[CoversNothing]
#[Group('spec:sparse-fieldsets')]
final class SerializerOverrideFieldSelectionTest extends TestCase
{
    private const string NARROWED = 'application/vnd.api+json; profile="' . FieldSelectionProfile::URI . '"';

    protected function setUp(): void
    {
        EaselSerializer::$selections = [];

        parent::setUp();
    }

    public function test_an_override_is_trimmed_to_the_selected_keys(): void
    {
        $response = $this->fetch('/api/boards/1?fields[boards]=name,values.cap,address.geo.lat,rows.*.size.w');

        $response->assertOk();
        self::assertSame([
            'name' => 'Seed',
            'values' => ['cap' => '10'],
            'address' => ['geo' => ['lat' => 51.5]],
            'rows' => [['size' => ['w' => 1]], ['size' => ['w' => 3]]],
        ], $response->json('data.attributes'));
        self::assertSame(self::NARROWED, $response->headers->get('Content-Type'));
        self::assertSame([FieldSelectionProfile::URI], $response->json('jsonapi.profile'));
    }

    public function test_an_override_narrows_every_resource_of_a_collection(): void
    {
        $response = $this->fetch('/api/boards?fields[boards]=values.region');

        $response->assertOk();
        self::assertSame(['values' => ['region' => 'EU']], $response->json('data.0.attributes'));
    }

    public function test_an_attribute_listed_whole_renders_whole(): void
    {
        $response = $this->fetch('/api/boards/1?fields[boards]=values,values.cap');

        $response->assertOk();
        self::assertSame(['values' => KeySelectionOverridesServiceProvider::VALUES], $response->json('data.attributes'));
        self::assertSame(self::MEDIA_TYPE, $response->headers->get('Content-Type'));
    }

    public function test_a_serializer_selecting_keys_receives_the_selection_and_is_still_trimmed(): void
    {
        $response = $this->fetch('/api/easels/1?fields[easels]=name,address.postcode,address.geo.lat');

        $response->assertOk();
        self::assertSame([['address', ['postcode', 'geo.lat']]], EaselSerializer::$selections);
        self::assertSame(['name' => 'Seed', 'address' => ['postcode' => 'AB1 2CD', 'geo' => ['lat' => 51.5]]], $response->json('data.attributes'));
    }

    public function test_a_key_the_resource_rejects_is_a_400(): void
    {
        foreach (['values.Cap', 'name.first'] as $member) {
            $response = $this->fetch('/api/boards/1?fields[boards]=' . $member);

            $response->assertStatus(400);
            self::assertSame('FIELDSET_MEMBER_UNRECOGNIZED', $response->json('errors.0.code'));
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
            KeySelectionOverridesServiceProvider::class,
        ];
    }

    /**
     * @return TestResponse<\Symfony\Component\HttpFoundation\Response>
     */
    private function fetch(string $uri): TestResponse
    {
        return $this->get($uri, ['Accept' => self::MEDIA_TYPE]);
    }
}
