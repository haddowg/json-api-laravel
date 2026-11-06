<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\OpenApi\Serve;

use haddowg\JsonApi\OpenApi\Serve\EntityTag;
use Symfony\Component\Yaml\Yaml;

/**
 * A format the OpenAPI document is served in. Every format encodes the same JSON
 * body, so a decorated document is built once whichever format a request asks for.
 *
 * @internal
 */
enum DocumentFormat: string
{
    case Json = 'json';
    case Yaml = 'yaml';

    /**
     * The flags the served YAML is dumped with. Empty arrays dump as `[]` so a JSON list
     * never comes back as a map.
     */
    public const int YAML_FLAGS = Yaml::DUMP_OBJECT_AS_MAP | Yaml::DUMP_EMPTY_ARRAY_AS_SEQUENCE;

    public function contentType(): string
    {
        return match ($this) {
            self::Json => 'application/json',
            self::Yaml => 'application/yaml',
        };
    }

    /**
     * The ETag hash for this format. JSON keeps the hash as it is, so its tags don't change
     * when YAML is added, and every other format folds its name in so no two formats of one
     * document share a tag.
     */
    public function tag(string $hash): string
    {
        return $this === self::Json ? $hash : EntityTag::hash($hash . "\n" . $this->value);
    }

    /**
     * The body in this format, from the JSON body.
     *
     * @throws \JsonException when `$json` isn't valid JSON
     */
    public function encode(string $json): string
    {
        if ($this === self::Json) {
            return $json;
        }

        // Decoding to objects keeps an empty JSON object a map.
        return Yaml::dump(\json_decode($json, false, 512, \JSON_THROW_ON_ERROR), 16, 2, self::YAML_FLAGS);
    }
}
