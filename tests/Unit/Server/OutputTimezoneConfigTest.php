<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Unit\Server;

use haddowg\JsonApiLaravel\Server\OutputTimezoneConfig;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
#[CoversClass(OutputTimezoneConfig::class)]
final class OutputTimezoneConfigTest extends TestCase
{
    #[Test]
    public function nothingConfiguredMeansNoConversion(): void
    {
        self::assertNull(OutputTimezoneConfig::forServer(null, ['default' => ['prefix' => 'api']], 'default'));
        self::assertNull(OutputTimezoneConfig::forServer('', ['default' => []], 'default'));
        self::assertNull(OutputTimezoneConfig::forServer(null, [], 'missing'));
    }

    #[Test]
    public function aServerWithoutItsOwnZoneInheritsTheGlobalOne(): void
    {
        self::assertSame('UTC', OutputTimezoneConfig::forServer('UTC', ['default' => []], 'default'));
        self::assertSame('UTC', OutputTimezoneConfig::forServer('UTC', ['default' => ['output_timezone' => null]], 'default'));
    }

    #[Test]
    public function aServersOwnZoneWins(): void
    {
        $servers = ['admin' => ['output_timezone' => 'America/New_York']];

        self::assertSame('America/New_York', OutputTimezoneConfig::forServer('UTC', $servers, 'admin'));
        self::assertSame('America/New_York', OutputTimezoneConfig::forServer(null, $servers, 'admin'));
    }

    #[Test]
    public function anEmptyServerZoneTurnsTheGlobalZoneOff(): void
    {
        self::assertNull(OutputTimezoneConfig::forServer('UTC', ['admin' => ['output_timezone' => '']], 'admin'));
    }

    #[Test]
    public function anUnknownGlobalZoneIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('jsonapi.output_timezone is "Mars/Olympus", which is not a timezone PHP recognises.');

        OutputTimezoneConfig::assertValid('Mars/Olympus', []);
    }

    #[Test]
    public function anUnknownServerZoneIsRejectedByName(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('jsonapi.servers.admin.output_timezone is "utc+1", which is not a timezone PHP recognises.');

        OutputTimezoneConfig::assertValid('UTC', ['default' => [], 'admin' => ['output_timezone' => 'utc+1']]);
    }

    #[Test]
    public function aNonStringZoneIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('jsonapi.output_timezone is int, which is not a timezone PHP recognises.');

        OutputTimezoneConfig::assertValid(1, []);
    }
}
