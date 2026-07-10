<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\OpenApi;

use haddowg\JsonApi\OpenApi\EnumDescriptionMode;
use haddowg\JsonApi\OpenApi\SchemaProjector;
use haddowg\JsonApi\Resource\AbstractResource;
use haddowg\JsonApiLaravel\Discovery\Discovery;
use haddowg\JsonApiLaravel\Server\ServerRegistry;
use haddowg\JsonApiLaravel\Server\TypeMetadataResolver;

/**
 * Builds the **standalone per-type JSON Schema 2020-12** documents for a server (PLAN
 * decision 11) — the `jsonapi:jsonschema:export` command's + `/schemas.json`
 * controller's source.
 *
 * Distinct from the {@see DocumentFactory}: that builds the whole OpenAPI document
 * (paths + envelopes + the component set, where a type's schema is `$ref`-able). This
 * builds a *self-contained* JSON Schema 2020-12 document per type — the type's
 * **resource object** (`type` const, `id`, `attributes`, …) projected by core's
 * {@see SchemaProjector} — wrapped with the canonical `$schema` dialect keyword and a
 * stable `$id` so the artifact is a valid, addressable schema document on its own.
 *
 * **Specificity vs the OpenAPI document.** The **attributes** are projected identically
 * to the OpenAPI document (the same {@see SchemaProjector::projectResourceObject()}), so
 * they agree exactly. The **relationships**, **links** and **meta** are deliberately the
 * projector's permissive `{type: object}` placeholders: the OpenAPI document narrows
 * those by `$ref`-ing shared/per-relation components, and a self-contained document has
 * no `components` to reference. So for relationships/links/meta the OpenAPI document is
 * the fuller contract; the standalone document is authoritative for a type's attribute
 * shape.
 *
 * A resource-less / standalone-serializer type (PLAN decision 3, bundle ADR 0024)
 * contributes a permissive resource-object schema (no field inventory, so an inline
 * `attributes: {type: object}`), so every registered type yields a document — the same
 * fieldless projection the OpenAPI document carries for it.
 */
final class JsonSchemaFactory
{
    private const DIALECT = 'https://json-schema.org/draft/2020-12/schema';

    private readonly SchemaProjector $schemaProjector;

    public function __construct(
        private readonly ServerRegistry $servers,
        private readonly TypeMetadataResolver $types,
        private readonly Discovery $discovery,
        EnumDescriptionMode $enumDescriptionMode = EnumDescriptionMode::Both,
    ) {
        $this->schemaProjector = new SchemaProjector($enumDescriptionMode);
    }

    /**
     * The standalone JSON Schema 2020-12 document for one `(server, type)`, as a
     * JSON-ready {@see \stdClass} carrying `$schema` + `$id` (the resource object).
     *
     * @throws \InvalidArgumentException when `$type` is not a registered JSON:API type
     *                                   for `$serverName` — a typo (`--type=articals`)
     *                                   fails loudly rather than emitting a bogus generic
     *                                   schema for a non-existent type
     */
    public function forType(string $type, ?string $serverName = null): \stdClass
    {
        $serverName ??= ServerRegistry::DEFAULT_SERVER;
        $server = $this->servers->get($serverName);

        if (!\in_array($type, $this->typeNamesFor($serverName), true)) {
            throw new \InvalidArgumentException(\sprintf(
                'Unknown JSON:API type "%s" for server "%s".',
                $type,
                $serverName,
            ));
        }

        $resource = $this->types->resourceFor($server, $type);
        $fields = $resource instanceof AbstractResource ? $resource->allFields() : [];

        $document = $this->schemaProjector->projectResourceObject($type, $fields)->toJson();
        // Prepend the dialect + identity keywords so the artifact is a valid, addressable
        // 2020-12 schema document on its own (toJson never sets them — a component schema
        // lives inside an OpenAPI document, this one stands alone).
        $document = (object) (['$schema' => self::DIALECT, '$id' => $this->schemaId($type)] + (array) $document);

        return $document;
    }

    /**
     * The standalone JSON Schema 2020-12 documents for **every** type registered for
     * `$serverName`, keyed by JSON:API type, in registration order.
     *
     * @return array<string, \stdClass>
     */
    public function forServer(?string $serverName = null): array
    {
        $serverName ??= ServerRegistry::DEFAULT_SERVER;

        $documents = [];
        foreach ($this->typeNamesFor($serverName) as $type) {
            if ($type === '') {
                continue;
            }

            $documents[$type] = $this->forType($type, $serverName);
        }

        return $documents;
    }

    /**
     * The standalone JSON Schema 2020-12 documents for **every** type across **every**
     * server, keyed by JSON:API type — the combined-mode aggregate. Types are unique
     * across servers (the combined document requires it), so the union carries no
     * collision.
     *
     * @return array<string, \stdClass>
     */
    public function combined(): array
    {
        $documents = [];
        foreach ($this->serverNames() as $serverName) {
            foreach ($this->forServer($serverName) as $type => $document) {
                $documents[$type] = $document;
            }
        }

        return $documents;
    }

    /**
     * The JSON:API type names registered for a server, in discovery order: the resources
     * first, then the standalone-serializer types (PLAN decision 3, bundle ADR 0024) —
     * the same resources-then-standalone ordering the OpenAPI document projects, so the
     * two artifacts enumerate the identical type set.
     *
     * @return list<string>
     */
    private function typeNamesFor(string $serverName): array
    {
        $names = [];
        foreach ($this->discovery->resourcesFor($serverName) as $descriptor) {
            $names[] = $descriptor->type;
        }

        foreach ($this->discovery->serializersFor($serverName) as $descriptor) {
            $names[] = $descriptor->type;
        }

        return $names;
    }

    /**
     * The declared server names, `default` first, drawn from the discovered resources'
     * and standalone serializers' server assignments (so a server exposing only
     * standalone types still exports).
     *
     * @return list<string>
     */
    private function serverNames(): array
    {
        $names = [];
        foreach ($this->discovery->resources() as $descriptor) {
            foreach ($descriptor->servers as $server) {
                if (!\in_array($server, $names, true)) {
                    $names[] = $server;
                }
            }
        }

        foreach ($this->discovery->serializers() as $descriptor) {
            foreach ($descriptor->servers as $server) {
                if (!\in_array($server, $names, true)) {
                    $names[] = $server;
                }
            }
        }

        $ordered = \in_array(ServerRegistry::DEFAULT_SERVER, $names, true) ? [ServerRegistry::DEFAULT_SERVER] : [];
        foreach ($names as $name) {
            if ($name !== ServerRegistry::DEFAULT_SERVER) {
                $ordered[] = $name;
            }
        }

        return $ordered;
    }

    private function schemaId(string $type): string
    {
        return \sprintf('urn:jsonapi:schema:%s', $type);
    }
}
