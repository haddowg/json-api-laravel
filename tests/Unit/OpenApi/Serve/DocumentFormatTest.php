<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Unit\OpenApi\Serve;

use haddowg\JsonApi\OpenApi\Serve\EntityTag;
use haddowg\JsonApiLaravel\OpenApi\Serve\DocumentFormat;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

/**
 * @internal
 */
final class DocumentFormatTest extends TestCase
{
    #[Test]
    public function yaml_parses_back_to_the_same_json(): void
    {
        // Deeper than the dump's inline level, so the innermost levels are written inline.
        $deep = ['leaf' => new \stdClass(), 'list' => []];
        for ($i = 0; $i < 20; ++$i) {
            $deep = [$deep];
        }

        $json = (string) \json_encode([
            'emptyObject' => new \stdClass(),
            'emptyList' => [],
            'listOfEmpties' => [new \stdClass(), []],
            'numericKeys' => ['200' => ['description' => 'OK'], '0' => 'zero'],
            'strings' => ['1', '1.0', '-2', '1e3', '0x1F', 'true', 'false', 'null', '~', 'yes', 'no', 'on', '', '2024-01-01', '#x', '@a', '*a', '- a', 'line' . "\n" . 'break', 'ünïcode'],
            'scalars' => [1, 1.5, 2.0, true, false, null],
            'deep' => $deep,
        ], \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE | \JSON_PRETTY_PRINT | \JSON_PRESERVE_ZERO_FRACTION);

        $yaml = DocumentFormat::Yaml->encode($json);

        $flags = \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE | \JSON_PRESERVE_ZERO_FRACTION;
        $this->assertSame(
            \json_encode(\json_decode($json, false, 512, \JSON_THROW_ON_ERROR), $flags),
            \json_encode(Yaml::parse($yaml, Yaml::PARSE_OBJECT_FOR_MAP), $flags),
        );
    }

    #[Test]
    public function json_is_served_as_it_is(): void
    {
        $this->assertSame('{"a": {}}', DocumentFormat::Json->encode('{"a": {}}'));
        $this->assertSame('application/json', DocumentFormat::Json->contentType());
        $this->assertSame('application/yaml', DocumentFormat::Yaml->contentType());
    }

    #[Test]
    public function only_yaml_folds_its_name_into_the_tag(): void
    {
        $this->assertSame('abc', DocumentFormat::Json->tag('abc'));
        $this->assertSame(EntityTag::hash("abc\nyaml"), DocumentFormat::Yaml->tag('abc'));
        $this->assertNotSame(DocumentFormat::Json->tag('abc'), DocumentFormat::Yaml->tag('abc'));
    }
}
