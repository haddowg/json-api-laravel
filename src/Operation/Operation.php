<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Operation;

/**
 * The five CRUD operations a JSON:API type can expose. The public DX type an
 * application lists on `#[AsJsonApiResource(operations:)]` to declare exactly which
 * endpoints the type serves; the route registrar emits exactly one route per
 * declared case.
 *
 * Each case value equals its name, so a descriptor can flow through the discovery
 * snapshot as a plain string. Each case maps to one route, with `$seg` the
 * resource's `uriType`:
 *  - {@see self::FetchCollection} → `GET /{seg}`
 *  - {@see self::FetchOne}        → `GET /{seg}/{id}`
 *  - {@see self::Create}          → `POST /{seg}`
 *  - {@see self::Update}          → `PATCH /{seg}/{id}`
 *  - {@see self::Delete}          → `DELETE /{seg}/{id}`
 */
enum Operation: string
{
    case FetchCollection = 'FetchCollection';
    case FetchOne = 'FetchOne';
    case Create = 'Create';
    case Update = 'Update';
    case Delete = 'Delete';
}
