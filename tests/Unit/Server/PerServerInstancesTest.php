<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Unit\Server;

use haddowg\JsonApiLaravel\Server\PerServerInstances;
use Illuminate\Container\Container;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Workbench\App\Timezone\GigResource;

/**
 * @internal
 */
#[CoversClass(PerServerInstances::class)]
final class PerServerInstancesTest extends TestCase
{
    #[Test]
    public function aSharedResourceIsClonedForTheSecondServer(): void
    {
        $container = new Container();
        $container->singleton(GigResource::class);
        $instances = new PerServerInstances($container);

        $first = ($instances->resolverFor('default'))(GigResource::class);
        $second = ($instances->resolverFor('admin'))(GigResource::class);

        self::assertSame($container->make(GigResource::class), $first);
        self::assertInstanceOf(GigResource::class, $second);
        self::assertNotSame($first, $second);
        self::assertSame($first, ($instances->resolverFor('default'))(GigResource::class));
    }

    #[Test]
    public function anUnsharedResourceIsLeftAsTheContainerBuiltIt(): void
    {
        $instances = new PerServerInstances(new Container());

        $default = ($instances->resolverFor('default'))(GigResource::class);
        $admin = ($instances->resolverFor('admin'))(GigResource::class);

        self::assertNotSame($default, $admin);
    }

    #[Test]
    public function aSharedObjectThatTakesNoResolverIsNotCloned(): void
    {
        $container = new Container();
        $shared = new \ArrayObject();
        $container->instance(\ArrayObject::class, $shared);
        $instances = new PerServerInstances($container);

        self::assertSame($shared, ($instances->resolverFor('default'))(\ArrayObject::class));
        self::assertSame($shared, ($instances->resolverFor('admin'))(\ArrayObject::class));
    }
}
