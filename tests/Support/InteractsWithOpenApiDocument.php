<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Support;

use Illuminate\Contracts\Foundation\Application;
use Illuminate\Testing\PendingCommand;

/**
 * Typed test helpers for the OpenAPI document-shape suites: resolving container
 * services, running artisan commands, and walking the projected document's nested
 * `array<string, mixed>` tree with assertions at each hop — so the tests stay
 * strictly-typed under PHPStan L9 without scattering `assert()`s through every case.
 *
 * @mixin \PHPUnit\Framework\TestCase
 */
trait InteractsWithOpenApiDocument
{
    /**
     * Resolves `$abstract` from the test application's container, typed.
     *
     * @template T of object
     *
     * @param class-string<T> $abstract
     *
     * @return T
     */
    protected function resolve(string $abstract): object
    {
        $app = $this->app;
        $this->assertInstanceOf(Application::class, $app);

        $instance = $app->make($abstract);
        $this->assertInstanceOf($abstract, $instance);

        return $instance;
    }

    /**
     * Runs an artisan command, returning the typed {@see PendingCommand} for exit-code /
     * output assertions.
     *
     * @param array<string, mixed> $parameters
     */
    protected function jsonApiArtisan(string $command, array $parameters = []): PendingCommand
    {
        $pending = $this->artisan($command, $parameters);
        $this->assertInstanceOf(PendingCommand::class, $pending);

        return $pending;
    }

    /**
     * Descends `$value` through `$keys`, asserting each hop is an array carrying the key,
     * and returns the reached (mixed) leaf.
     */
    protected function at(mixed $value, int|string ...$keys): mixed
    {
        foreach ($keys as $key) {
            $this->assertIsArray($value);
            $this->assertArrayHasKey($key, $value);
            $value = $value[$key];
        }

        return $value;
    }

    /**
     * The array reached by descending `$keys` (asserting it is an array).
     *
     * @return array<array-key, mixed>
     */
    protected function arrayAt(mixed $value, int|string ...$keys): array
    {
        $reached = $this->at($value, ...$keys);
        $this->assertIsArray($reached);

        return $reached;
    }

    /**
     * The string reached by descending `$keys` (asserting it is a string).
     */
    protected function stringAt(mixed $value, int|string ...$keys): string
    {
        $reached = $this->at($value, ...$keys);
        $this->assertIsString($reached);

        return $reached;
    }

    /**
     * The `name` values of the OpenAPI parameters at `paths.{path}.{method}.parameters`.
     *
     * @param array<string, mixed> $doc
     *
     * @return list<string>
     */
    protected function parameterNames(array $doc, string $path, string $method): array
    {
        $names = [];
        foreach ($this->arrayAt($doc, 'paths', $path, $method, 'parameters') as $parameter) {
            if (\is_array($parameter) && isset($parameter['name']) && \is_string($parameter['name'])) {
                $names[] = $parameter['name'];
            }
        }

        return $names;
    }
}
