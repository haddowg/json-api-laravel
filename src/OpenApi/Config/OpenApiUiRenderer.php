<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\OpenApi\Config;

/**
 * The documentation-viewer renderer the UI route embeds (PLAN decision 11): **one** of
 * Swagger UI or ReDoc, never both — the `jsonapi.openapi.ui.renderer` choice.
 */
enum OpenApiUiRenderer: string
{
    case Swagger = 'swagger';
    case Redoc = 'redoc';
}
