<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Feature\ActionFilters;

use haddowg\JsonApiLaravel\DataProvider\InMemoryDataProvider;
use haddowg\JsonApiLaravel\Facades\JsonApi;
use haddowg\JsonApiLaravel\JsonApiServiceProvider;
use haddowg\JsonApiLaravel\Tests\Fixtures\ActionFilters\GadgetResource;
use haddowg\JsonApiLaravel\Tests\Fixtures\ActionFilters\Invalid\FilteredResourceAction;
use haddowg\JsonApiLaravel\Tests\Fixtures\ActionFilters\Invalid\UndeclaredFilterAction;
use Orchestra\Testbench\TestCase as Orchestra;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;

/**
 * Filters on a resource-scope action, or a listed filter the type does not declare, stop the
 * application booting.
 *
 * @internal
 */
#[CoversNothing]
final class ActionFiltersBootGuardTest extends Orchestra
{
    /**
     * @var class-string
     */
    private string $action = FilteredResourceAction::class;

    private ?\Throwable $bootFailure = null;

    protected function setUp(): void
    {
        $this->action = \str_contains($this->name(), 'Undeclared') ? UndeclaredFilterAction::class : FilteredResourceAction::class;

        try {
            parent::setUp();
        } catch (\LogicException $e) {
            $this->bootFailure = $e;
        }
    }

    #[Test]
    public function filtersOnAResourceScopeActionFailTheBoot(): void
    {
        self::assertInstanceOf(\LogicException::class, $this->bootFailure);
        self::assertStringContainsString(FilteredResourceAction::class, $this->bootFailure->getMessage());
        self::assertStringContainsString('Only a collection-scope action may take filters', $this->bootFailure->getMessage());
    }

    #[Test]
    public function anUndeclaredListedFilterFailsTheBoot(): void
    {
        self::assertInstanceOf(\LogicException::class, $this->bootFailure);
        self::assertStringContainsString(UndeclaredFilterAction::class, $this->bootFailure->getMessage());
        self::assertStringContainsString('takes the filter "shape", which the type does not declare', $this->bootFailure->getMessage());
    }

    /**
     * @param \Illuminate\Foundation\Application $app
     *
     * @return array<int, class-string>
     */
    protected function getPackageProviders($app): array
    {
        return [JsonApiServiceProvider::class];
    }

    /**
     * @param \Illuminate\Foundation\Application $app
     */
    protected function defineEnvironment($app): void
    {
        JsonApi::register([GadgetResource::class, $this->action]);
        JsonApi::provider(new InMemoryDataProvider('gadgets', []));
    }
}
