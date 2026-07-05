<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\OpenApi\Config;

/**
 * The resolved `jsonapi.openapi.ui.*` configuration (PLAN decision 11) — the single
 * config-driven documentation-viewer route's settings.
 *
 * A pure-scalar immutable carrier: whether the viewer route is registered, which
 * renderer the page embeds ({@see OpenApiUiRenderer} — Swagger UI or ReDoc, one not
 * both), the path it mounts at, and an optional CDN base-URL override (null = the
 * controller's pinned package default, so a self-hosted/air-gapped asset origin can
 * replace the public CDN).
 */
final readonly class OpenApiUiConfig
{
    public function __construct(
        public bool $enabled,
        public OpenApiUiRenderer $renderer,
        public string $path,
        public ?string $cdn,
    ) {}
}
