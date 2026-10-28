<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Unit\Http;

use haddowg\JsonApiLaravel\Http\ResponseHeaderDefaultsConfig;
use haddowg\JsonApiLaravel\Http\ResponseHeaderOperation;
use haddowg\JsonApiLaravel\Http\ResponseHeadersRegistry;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
final class ResponseHeaderDefaultsConfigTest extends TestCase
{
    #[Test]
    public function aServersBlockIsReadKeyedByServerAndUnsetValuesAreLeftOut(): void
    {
        $servers = [
            'default' => ['prefix' => 'api'],
            'v5' => ['prefix' => 'v5', 'defaults' => [
                'deprecation' => null,
                'sunset' => 'Sat, 01 May 2027 00:00:00 GMT',
                'sunset_link' => 'https://example.test/v6',
                'cache_headers' => ['max_age' => 30, 'public' => null],
            ]],
        ];

        self::assertSame(['v5' => [
            'cache' => ['max_age' => 30],
            'deprecation' => ['sunset' => 'Sat, 01 May 2027 00:00:00 GMT', 'sunset_link' => 'https://example.test/v6'],
        ]], ResponseHeaderDefaultsConfig::forServers($servers));
    }

    #[Test]
    public function theShippedGlobalBlockIsValid(): void
    {
        $block = ResponseHeaderDefaultsConfig::fromBlock(
            ['cache_headers' => [], 'deprecation' => null, 'sunset' => null, 'sunset_link' => null],
            'jsonapi.defaults',
        );

        self::assertSame(['cache' => [], 'deprecation' => []], $block);
    }

    /**
     * @return iterable<string, array{array<string, mixed>, string}>
     */
    public static function badBlocks(): iterable
    {
        yield 'a non-integer max_age' => [['cache_headers' => ['max_age' => 'soon']], '%s.cache_headers.max_age must be an integer or null, got string.'];
        yield 'a non-boolean public' => [['cache_headers' => ['public' => 'yes']], '%s.cache_headers.public must be true, false or null, got string.'];
        yield 'a non-list vary' => [['cache_headers' => ['vary' => 'Accept']], '%s.cache_headers.vary must be a list of header names or null, got string.'];
        yield 'an unknown cache directive' => [['cache_headers' => ['stale' => 1]], '%s.cache_headers.stale is not a cache directive'];
        yield 'a numeric deprecation' => [['deprecation' => 1688169599], '%s.deprecation must be true, false, a date string or null, got int.'];
        yield 'a numeric sunset' => [['sunset' => 1688169599], '%s.sunset must be a string or null, got int.'];
        yield 'a numeric sunset_link' => [['sunset_link' => 6], '%s.sunset_link must be a string or null, got int.'];
        yield 'an unknown key' => [['expires' => 'never'], '%s.expires is not a response-header default'];
    }

    /**
     * @param array<string, mixed> $block
     */
    #[Test]
    #[DataProvider('badBlocks')]
    public function aBadServerBlockFailsTheBoot(array $block, string $message): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage(\sprintf($message, 'jsonapi.servers.v5.defaults'));

        ResponseHeaderDefaultsConfig::assertValid(null, ['default' => [], 'v5' => ['defaults' => $block]]);
    }

    /**
     * @param array<string, mixed> $block
     */
    #[Test]
    #[DataProvider('badBlocks')]
    public function theSameBadGlobalBlockFailsTheBoot(array $block, string $message): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage(\sprintf($message, 'jsonapi.defaults'));

        ResponseHeaderDefaultsConfig::assertValid($block, ['default' => []]);
    }

    #[Test]
    public function theRegistryLayersAServersDefaultsBetweenTheResourceAndTheGlobalOnes(): void
    {
        $registry = new ResponseHeadersRegistry(
            defaultCache: ['max_age' => 120, 'public' => true, 'vary' => ['Accept']],
            defaultDeprecation: ['deprecation' => true, 'sunset_link' => 'https://example.test/global'],
            byServer: ['v5' => ['dated' => ['deprecation' => ['sunset' => 'Sat, 31 Dec 2050 23:59:59 GMT'], 'cache' => ['max_age' => 60]]]],
            serverDefaults: ['v5' => [
                'cache' => ['max_age' => 30, 'vary' => ['Accept-Language']],
                'deprecation' => ['sunset' => 'Sat, 01 May 2027 00:00:00 GMT'],
            ]],
        );

        $plain = $registry->deprecationFor('widgets', 'v5');
        self::assertNotNull($plain);
        self::assertTrue($plain->deprecation);
        self::assertSame('Sat, 01 May 2027 00:00:00 GMT', $plain->sunset);
        self::assertSame('https://example.test/global', $plain->sunsetLink);
        self::assertSame('Sat, 31 Dec 2050 23:59:59 GMT', $registry->deprecationFor('dated', 'v5')?->sunset);

        $cache = $registry->cacheFor('widgets', ResponseHeaderOperation::Read, 'v5');
        self::assertNotNull($cache);
        self::assertSame(30, $cache->maxAge);
        self::assertTrue($cache->public);
        self::assertSame(['Accept', 'Accept-Language'], $cache->vary);
        self::assertSame(60, $registry->cacheFor('dated', ResponseHeaderOperation::Read, 'v5')?->maxAge);

        self::assertNull($registry->deprecationFor('widgets', 'v6')?->sunset);
        self::assertSame(120, $registry->cacheFor('widgets', ResponseHeaderOperation::Read)?->maxAge);
    }
}
