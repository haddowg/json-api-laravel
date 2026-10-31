<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\OpenApi\Config;

use haddowg\JsonApiLaravel\Http\OpenApiUiController;

/**
 * The resolved `jsonapi.openapi.ui.*` configuration — the single
 * config-driven documentation-viewer route's settings.
 *
 * A pure-scalar immutable carrier: whether the viewer route is registered, which
 * renderer the page embeds ({@see OpenApiUiRenderer} — Swagger UI or ReDoc, one not
 * both), the path it mounts at, and an optional CDN base-URL override (null = the
 * controller's pinned package default, so a self-hosted/air-gapped asset origin can
 * replace the public CDN), the exact ReDoc version the redoc renderer loads, and whether
 * ReDoc 3.x may send telemetry.
 */
final readonly class OpenApiUiConfig
{
    public function __construct(
        public bool $enabled,
        public OpenApiUiRenderer $renderer,
        public string $path,
        public ?string $cdn,
        public string $redocVersion = OpenApiUiController::REDOC_VERSION,
        public bool $redocTelemetry = false,
    ) {}

    /**
     * The `jsonapi.openapi.ui.redoc_version` in effect: null (an unset key) is the
     * default, anything else must be an exact 2.x or 3.x release.
     *
     * @throws \InvalidArgumentException when the value is not an exact 2.x or 3.x version
     */
    public static function redocVersion(mixed $value): string
    {
        if ($value === null) {
            return OpenApiUiController::REDOC_VERSION;
        }

        if (!\is_string($value) || \preg_match('/^[23]\.\d+\.\d+$/', $value) !== 1) {
            throw new \InvalidArgumentException(\sprintf(
                'jsonapi.openapi.ui.redoc_version must be an exact 2.x or 3.x version such as "3.0.1" or "2.5.4", got %s.',
                \json_encode($value),
            ));
        }

        return $value;
    }
}
