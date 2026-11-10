<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Feature\LoadedLinkage;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;

#[CoversNothing]
final class EloquentLoadedLinkageTest extends LoadedLinkageTestCase
{
    protected function store(): string
    {
        return 'eloquent';
    }

    /**
     * A lazy to-many renders the same links-only relationship whether or not the
     * attribute loaded the relation first.
     */
    #[Test]
    public function aLazyToManyLoadedAfterThePreloadStaysLinksOnly(): void
    {
        $response = $this->read('/templates/1');

        foreach (['bindings', 'sharedBindings'] as $name) {
            $relationship = $response->json('data.relationships.' . $name);
            self::assertIsArray($relationship, $name);
            self::assertArrayNotHasKey('data', $relationship, $name);
            self::assertArrayHasKey('links', $relationship, $name);
        }
    }
}
