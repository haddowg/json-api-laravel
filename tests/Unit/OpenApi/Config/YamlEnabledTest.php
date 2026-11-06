<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Unit\OpenApi\Config;

use haddowg\JsonApiLaravel\OpenApi\Config\OpenApiConfigResolver;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
final class YamlEnabledTest extends TestCase
{
    #[Test]
    public function null_follows_whether_symfony_yaml_is_installed(): void
    {
        $this->assertTrue(OpenApiConfigResolver::yamlEnabled(null, true));
        $this->assertFalse(OpenApiConfigResolver::yamlEnabled(null, false));
    }

    #[Test]
    public function false_turns_it_off_either_way(): void
    {
        $this->assertFalse(OpenApiConfigResolver::yamlEnabled(false, true));
        $this->assertFalse(OpenApiConfigResolver::yamlEnabled(false, false));
    }

    #[Test]
    public function true_needs_symfony_yaml(): void
    {
        $this->assertTrue(OpenApiConfigResolver::yamlEnabled(true, true));

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('composer require symfony/yaml');
        OpenApiConfigResolver::yamlEnabled(true, false);
    }

    #[Test]
    public function the_resolved_config_carries_the_yaml_settings(): void
    {
        $config = (new OpenApiConfigResolver())->resolve(['yaml' => ['enabled' => false, 'path' => 'spec.yaml']], ['default']);
        $this->assertFalse($config->yamlEnabled);
        $this->assertSame('/spec.yaml', $config->yamlPath);

        $defaults = (new OpenApiConfigResolver())->resolve([], ['default']);
        $this->assertSame(\class_exists(\Symfony\Component\Yaml\Yaml::class), $defaults->yamlEnabled);
        $this->assertSame('/docs.yaml', $defaults->yamlPath);
    }
}
