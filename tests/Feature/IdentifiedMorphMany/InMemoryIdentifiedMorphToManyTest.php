<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Feature\IdentifiedMorphMany;

use haddowg\JsonApiLaravel\DataProvider\InMemoryDataProvider;
use haddowg\JsonApiLaravel\Tests\Fixtures\IdentifiedMorphMany\UserRecord;

/**
 * @internal
 */
class InMemoryIdentifiedMorphToManyTest extends IdentifiedMorphToManyTestCase
{
    protected function store(): string
    {
        return 'memory';
    }

    protected function hides(): bool
    {
        return false;
    }

    protected function storedCompanies(int $user): array
    {
        return \array_map(static fn(array $pair): string => $pair[0] . ':' . $pair[1], $this->user($user)->companies);
    }

    protected function storeCompany(int $user, string $type, int $id): void
    {
        $this->user($user)->companies[] = [$type, $id];
    }

    private function user(int $id): UserRecord
    {
        $provider = $this->app?->make('mtm.users');
        self::assertInstanceOf(InMemoryDataProvider::class, $provider);
        $user = $provider->store()->find((string) $id);
        self::assertInstanceOf(UserRecord::class, $user);

        return $user;
    }
}
