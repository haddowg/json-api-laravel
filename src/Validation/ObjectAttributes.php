<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Validation;

use haddowg\JsonApi\Request\JsonApiRequestInterface;

/**
 * A write body's `data.attributes` decoded with JSON objects kept as objects, for schema
 * value validation only. The parsed body is an associative decode, which turns a client's
 * `{}` into `[]`, and opis then fails a `type: object` schema on it. Hydration keeps the
 * associative parsed body.
 *
 * @internal
 */
final class ObjectAttributes
{
    /**
     * The request attribute a synthetic sub-request (an atomic operation) carries its own
     * view on, since its raw body is the whole batch rather than its own document.
     */
    public const string REQUEST_ATTRIBUTE = '_jsonapi_object_attributes';

    /**
     * The request's attributes as an object, or null when the body has none to read.
     */
    public static function of(JsonApiRequestInterface $request): ?\stdClass
    {
        if (\array_key_exists(self::REQUEST_ATTRIBUTE, $request->getAttributes())) {
            $view = $request->getAttribute(self::REQUEST_ATTRIBUTE);

            return $view instanceof \stdClass ? $view : null;
        }

        return self::inDocument(self::decode((string) $request->getBody()));
    }

    /**
     * The `data.attributes` object of an object-decoded document (or atomic operation).
     */
    public static function inDocument(mixed $document): ?\stdClass
    {
        $data = $document instanceof \stdClass ? ($document->data ?? null) : null;
        $attributes = $data instanceof \stdClass ? ($data->attributes ?? null) : null;

        return $attributes instanceof \stdClass ? $attributes : null;
    }

    /**
     * Decodes a JSON body with objects kept as objects; null when it is empty or malformed
     * (a malformed body is rejected elsewhere).
     */
    public static function decode(string $json): mixed
    {
        if ($json === '') {
            return null;
        }

        try {
            return \json_decode($json, false, 512, \JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return null;
        }
    }
}
