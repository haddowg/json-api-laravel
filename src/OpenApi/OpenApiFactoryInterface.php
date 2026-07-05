<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\OpenApi;

use haddowg\JsonApi\OpenApi\OpenApi;

/**
 * The wholesale-customisation decorator seam — the application's last word over the
 * generated OpenAPI document.
 *
 * Config (`info` / `servers` / `security` / `tags`) covers the common cases; this
 * interface is the escape hatch for anything the projection cannot express
 * declaratively — adding a server variable, an extra security scheme,
 * per-CRUD-operation tags, vendor extensions, hand-written examples, or rewriting any
 * part of the document.
 *
 * An implementation is discovered (any class under a scanned path implementing this
 * interface) or registered explicitly, and composed by the {@see DocumentFactory}
 * **after** the core projection. Because every build path — the optimize warmer, the
 * controller's dev lazy-build, and the CLI export — flows through the
 * {@see DocumentFactory}, a decorator runs for all three uniformly.
 *
 * Decorators receive the built immutable {@see OpenApi} VO and **return** a (typically
 * `with*`-derived) mutated one.
 */
interface OpenApiFactoryInterface
{
    /**
     * Mutate (or pass through) the document built for `$server`.
     *
     * @param OpenApi $document the freshly projected document (or the result of an
     *                          earlier-applied decorator)
     * @param string  $server   the server name the document was built for — the implicit
     *                          `default` server, a named server, or the combined-mode
     *                          token {@see DocumentFactory::COMBINED_KEY}
     *
     * @return OpenApi the document to serve / warm / export (return `$document`
     *                 unchanged to opt out for a given server)
     */
    public function decorate(OpenApi $document, string $server): OpenApi;
}
