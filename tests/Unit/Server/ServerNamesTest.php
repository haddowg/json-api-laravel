<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Unit\Server;

use haddowg\JsonApi\Request\JsonApiRequestInterface;
use haddowg\JsonApi\Server\Server;
use haddowg\JsonApiLaravel\Operation\TargetResolver;
use haddowg\JsonApiLaravel\Server\ServerNames;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
#[CoversClass(ServerNames::class)]
final class ServerNamesTest extends TestCase
{
    #[Test]
    public function theRequestServerWins(): void
    {
        $names = new ServerNames();
        $server = Server::make();
        $names->register($server, 'admin');

        self::assertSame('public', $names->resolve($this->request('public'), $server));
    }

    #[Test]
    public function withoutARequestServerTheOperationsServerIsNamed(): void
    {
        $names = new ServerNames();
        $server = Server::make();
        $names->register($server, 'admin');

        self::assertSame('admin', $names->resolve($this->request(null), $server));
        self::assertSame('admin', $names->resolve(null, $server->withBaseUri('https://example.test/admin-api')));
    }

    #[Test]
    public function anUnknownServerIsTheDefaultServer(): void
    {
        $names = new ServerNames();

        self::assertSame('default', $names->resolve(null, Server::make()));
        self::assertSame('default', $names->resolve(null));
    }

    private function request(?string $server): JsonApiRequestInterface
    {
        $request = $this->createStub(JsonApiRequestInterface::class);
        $request->method('getAttribute')->willReturnCallback(
            static fn(string $name): ?string => $name === TargetResolver::SERVER_ATTRIBUTE ? $server : null,
        );

        return $request;
    }
}
