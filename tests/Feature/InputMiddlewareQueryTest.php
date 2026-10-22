<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Feature;

use haddowg\JsonApiLaravel\Tests\Eloquent\EloquentTestCase;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Foundation\Http\Middleware\ConvertEmptyStringsToNull;
use Illuminate\Foundation\Http\Middleware\TrimStrings;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Laravel's default global middleware (`TrimStrings`, `ConvertEmptyStringsToNull`)
 * rewrite the query bag. The JSON:API query parameters must reach core as the client sent
 * them, so a request answers the same with that middleware on as with it off.
 *
 * @internal
 */
#[CoversNothing]
final class InputMiddlewareQueryTest extends EloquentTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->seedFixtures();

        // Testbench's default HTTP kernel carries both; without them these tests prove nothing.
        $kernel = $this->app?->make(Kernel::class);
        self::assertInstanceOf(\Illuminate\Foundation\Http\Kernel::class, $kernel);
        self::assertTrue($kernel->hasMiddleware(TrimStrings::class));
        self::assertTrue($kernel->hasMiddleware(ConvertEmptyStringsToNull::class));
    }

    public function test_an_empty_sparse_fieldset_renders_no_attributes_or_relationships(): void
    {
        $response = $this->get('/api/artists?fields[artists]=', ['Accept' => self::MEDIA_TYPE]);

        $response->assertOk();
        $response->assertJsonCount(2, 'data');
        $data = $response->json('data');
        self::assertIsArray($data);
        foreach ($data as $artist) {
            self::assertIsArray($artist);
            self::assertSame('artists', $artist['type']);
            self::assertArrayNotHasKey('attributes', $artist);
            self::assertArrayNotHasKey('relationships', $artist);
        }
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function queries(): iterable
    {
        yield 'empty fieldset' => ['/api/artists?fields[artists]='];
        yield 'empty contains filter' => ['/api/artists?filter[nameContains]='];
        yield 'empty range bounds' => ['/api/artists?filter[trackRange][min]=&filter[trackRange][max]='];
        yield 'empty singular filter' => ['/api/artists?filter[slug]='];
        yield 'empty presence filter' => ['/api/artists?filter[noWebsite]='];
        yield 'empty sort' => ['/api/artists?sort='];
        yield 'empty include' => ['/api/artists?include='];
        yield 'empty page size' => ['/api/artists?page[size]='];
        yield 'padded filter value' => ['/api/artists?filter[nameContains]=%20RADIO%20'];
        yield 'non-empty values' => ['/api/albums?include=artist&fields[albums]=title,artist&fields[artists]=name&sort=-releasedAt&page[size]=1&filter[status]=released,archived'];
    }

    #[DataProvider('queries')]
    public function test_a_query_answers_the_same_as_without_the_input_middleware(string $uri): void
    {
        $with = $this->get($uri, ['Accept' => self::MEDIA_TYPE]);

        $this->withoutMiddleware([TrimStrings::class, ConvertEmptyStringsToNull::class]);
        $without = $this->get($uri, ['Accept' => self::MEDIA_TYPE]);

        $without->assertOk();
        self::assertSame($without->getStatusCode(), $with->getStatusCode());
        self::assertSame($without->getContent(), $with->getContent());
    }

    public function test_a_padded_filter_value_is_not_trimmed(): void
    {
        // TrimStrings would turn ` RADIO ` into `RADIO` and match Radiohead.
        $this->get('/api/artists?filter[nameContains]=%20RADIO%20', ['Accept' => self::MEDIA_TYPE])
            ->assertOk()
            ->assertJsonCount(0, 'data');
    }
}
