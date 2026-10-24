<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Unit\Server;

use haddowg\JsonApiLaravel\Server\LinkObjectsConfig;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
final class LinkObjectsConfigTest extends TestCase
{
    #[Test]
    public function linkObjectsAreOnByDefault(): void
    {
        self::assertTrue(LinkObjectsConfig::forServer(null, ['default' => []], 'default'));
    }

    #[Test]
    public function aServerInheritsTheGlobalSetting(): void
    {
        self::assertFalse(LinkObjectsConfig::forServer(false, ['default' => [], 'admin' => ['link_objects' => null]], 'admin'));
        self::assertTrue(LinkObjectsConfig::forServer(true, ['default' => []], 'default'));
    }

    #[Test]
    public function aServersOwnSettingWins(): void
    {
        $servers = ['default' => [], 'admin' => ['link_objects' => false], 'partner' => ['link_objects' => true]];

        self::assertFalse(LinkObjectsConfig::forServer(true, $servers, 'admin'));
        self::assertTrue(LinkObjectsConfig::forServer(false, $servers, 'partner'));
        self::assertFalse(LinkObjectsConfig::forServer(false, $servers, 'default'));
    }

    #[Test]
    public function aNonBooleanFailsTheBoot(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('jsonapi.servers.admin.link_objects must be true, false or null, got string.');

        LinkObjectsConfig::assertValid(true, ['default' => [], 'admin' => ['link_objects' => 'no']]);
    }

    #[Test]
    public function aNonBooleanGlobalFailsTheBoot(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('jsonapi.link_objects must be true, false or null, got int.');

        LinkObjectsConfig::assertValid(0, ['default' => []]);
    }
}
