<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Feature;

use haddowg\JsonApiLaravel\JsonApiServiceProvider;
use haddowg\JsonApiLaravel\OpenApi\DocumentFactory;
use haddowg\JsonApiLaravel\Tests\Fixtures\OperationErrors\OperationErrorsServiceProvider;
use Orchestra\Testbench\TestCase as Orchestra;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;

/**
 * A code a type declares through `operationErrors`, or an action through `errors`, is offered
 * by those operations' narrowed error responses alone.
 *
 * @internal
 */
final class OpenApiOperationErrorsTest extends Orchestra
{
    #[Test]
    #[Group('openapi')]
    public function a_code_declared_for_an_operation_is_offered_only_there(): void
    {
        $document = $this->document();

        $this->assertSame('#/components/responses/ConflictWithLockerLocked', $this->at($document, 'paths', '/lockers/{id}', 'patch', 'responses', '409', '$ref'));
        $this->assertSame('#/components/responses/Conflict', $this->at($document, 'paths', '/lockers', 'post', 'responses', '409', '$ref'));
        $narrowed = $this->at($document, 'components', 'schemas', 'ErrorDocument409WithLockerLocked', 'properties', 'errors', 'items', 'anyOf');
        $shared = $this->at($document, 'components', 'schemas', 'ErrorDocument409', 'properties', 'errors', 'items', 'anyOf');
        $this->assertIsArray($narrowed);
        $this->assertIsArray($shared);
        $this->assertContains(['$ref' => '#/components/schemas/Error409LockerLocked'], $narrowed);
        $this->assertNotContains(['$ref' => '#/components/schemas/Error409LockerLocked'], $shared);
    }

    #[Test]
    #[Group('openapi')]
    public function a_code_an_action_declares_is_offered_only_by_the_action(): void
    {
        $document = $this->document();

        $this->assertSame(
            '#/components/responses/Status402WithDepositRequired',
            $this->at($document, 'paths', '/lockers/{id}/-actions/open', 'post', 'responses', '402', '$ref'),
        );
        $patch = $this->at($document, 'paths', '/lockers/{id}', 'patch', 'responses');
        $this->assertIsArray($patch);
        $this->assertArrayNotHasKey('402', $patch);
    }

    /**
     * @return array<array-key, mixed>
     */
    private function document(): array
    {
        $factory = $this->app?->make(DocumentFactory::class);
        $this->assertInstanceOf(DocumentFactory::class, $factory);

        return $factory->forServer('default')->toArray();
    }

    /**
     * @param array<array-key, mixed> $data
     */
    private function at(array $data, string ...$path): mixed
    {
        foreach ($path as $segment) {
            $this->assertIsArray($data);
            $this->assertArrayHasKey($segment, $data, \implode('.', $path));
            $data = $data[$segment];
        }

        return $data;
    }

    /**
     * @param \Illuminate\Foundation\Application $app
     *
     * @return array<int, class-string>
     */
    protected function getPackageProviders($app): array
    {
        return [
            JsonApiServiceProvider::class,
            OperationErrorsServiceProvider::class,
        ];
    }
}
