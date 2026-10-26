<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Unit\OpenApi\Config;

use haddowg\JsonApiLaravel\OpenApi\Config\ExamplesConfig;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
final class ExamplesConfigTest extends TestCase
{
    #[Test]
    public function examplesAreOnByDefault(): void
    {
        self::assertTrue(ExamplesConfig::forServer(null, ['default' => []], 'default'));
    }

    #[Test]
    public function aServerInheritsTheGlobalSetting(): void
    {
        self::assertFalse(ExamplesConfig::forServer(false, ['default' => [], 'admin' => ['openapi_examples' => null]], 'admin'));
        self::assertTrue(ExamplesConfig::forServer(true, ['default' => []], 'default'));
    }

    #[Test]
    public function aServersOwnSettingWins(): void
    {
        $servers = ['default' => [], 'admin' => ['openapi_examples' => false], 'partner' => ['openapi_examples' => true]];

        self::assertFalse(ExamplesConfig::forServer(true, $servers, 'admin'));
        self::assertTrue(ExamplesConfig::forServer(false, $servers, 'partner'));
        self::assertFalse(ExamplesConfig::forServer(false, $servers, 'default'));
    }

    #[Test]
    public function aNonBooleanFailsTheBoot(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('jsonapi.servers.admin.openapi_examples must be true, false or null, got string.');

        ExamplesConfig::assertValid(true, ['default' => [], 'admin' => ['openapi_examples' => 'no']]);
    }

    #[Test]
    public function aNonBooleanGlobalFailsTheBoot(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('jsonapi.openapi.examples must be true, false or null, got int.');

        ExamplesConfig::assertValid(0, ['default' => []]);
    }
}
