<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Unit\Http;

use haddowg\JsonApiLaravel\Http\WireQuery;
use Illuminate\Http\Request;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
#[CoversClass(WireQuery::class)]
final class WireQueryTest extends TestCase
{
    public function test_it_reads_the_raw_query_string_not_the_rewritten_bag(): void
    {
        $request = Request::create('/api/artists?fields[artists]=&filter[name]=%20Radio%20');
        // What TrimStrings + ConvertEmptyStringsToNull leave in the bag.
        $request->query->replace(['fields' => ['artists' => null], 'filter' => ['name' => 'Radio']]);

        self::assertSame(['fields' => ['artists' => ''], 'filter' => ['name' => ' Radio ']], WireQuery::params($request));
    }

    public function test_it_matches_the_query_bag_php_parsed(): void
    {
        $request = Request::create('/api/albums?include=artist,tracks&fields[albums]=title&sort=-releasedAt&page[number]=2&page[size]=5&filter[status]=released,archived&filter[rating][min]=3');

        self::assertSame($request->query->all(), WireQuery::params($request));
    }

    public function test_it_falls_back_to_the_query_bag_without_a_server_query_string(): void
    {
        $request = new Request(['fields' => ['artists' => 'name']]);

        self::assertSame(['fields' => ['artists' => 'name']], WireQuery::params($request));
    }
}
