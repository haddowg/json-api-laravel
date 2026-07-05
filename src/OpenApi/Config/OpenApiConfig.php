<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\OpenApi\Config;

use haddowg\JsonApi\OpenApi\EnumDescriptionMode;
use haddowg\JsonApiLaravel\OpenApi\Metadata\ServerDocumentConfig;

/**
 * The resolved `jsonapi.openapi.*` configuration (PLAN decision 11), built by
 * {@see OpenApiConfigResolver} from the runtime config array and threaded through the
 * package's OpenAPI wiring.
 *
 * It is a plain immutable carrier of the document-generation settings (enabled,
 * exposure, multi-server mode, enum-description mode, json path, public path, the
 * {@see OpenApiUiConfig} viewer settings) and the per-server {@see ServerDocumentConfig}
 * map (info / servers / security / tags) the
 * {@see \haddowg\JsonApiLaravel\OpenApi\Metadata\MetadataSource} folds in.
 */
final readonly class OpenApiConfig
{
    /**
     * @param array<string, ServerDocumentConfig> $serverDocuments the per-server document config, keyed by server name
     */
    public function __construct(
        public bool $enabled,
        public bool $exposeInProd,
        public bool $combined,
        public EnumDescriptionMode $enumDescriptionMode,
        public string $jsonPath,
        public ?string $publicPath,
        public OpenApiUiConfig $ui,
        public array $serverDocuments,
        public bool $jsonSchemaEnabled = true,
        public string $jsonSchemaPath = '/schemas.json',
        public bool $describedby = true,
    ) {}
}
