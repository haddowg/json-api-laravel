<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Feature\ActionFilters;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;

/**
 * {@see ActionFiltersTestCase} over the in-memory provider, which has no query builder.
 *
 * @internal
 */
#[CoversNothing]
final class InMemoryActionFiltersTest extends ActionFiltersTestCase
{
    #[Test]
    public function queryIsNullWithoutANativeBuilder(): void
    {
        $this->countAction('?filter[colour]=red')
            ->assertJsonPath('meta.builder', false)
            ->assertJsonPath('meta.queryIds', null);
    }

    protected function store(): string
    {
        return 'memory';
    }
}
