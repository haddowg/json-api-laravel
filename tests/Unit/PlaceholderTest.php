<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Unit;

use haddowg\JsonApi\Server\Server;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;

/**
 * Placeholder that keeps the unit suite non-empty; it doubles
 * as a smoke test that the framework-agnostic core dependency autoloads.
 *
 * @internal
 */
#[CoversNothing]
final class PlaceholderTest extends TestCase
{
    public function test_the_core_dependency_is_installed_and_autoloads(): void
    {
        self::assertTrue(\class_exists(Server::class));
    }
}
