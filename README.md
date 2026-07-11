# json-api-laravel

[![CI](https://github.com/haddowg/json-api-laravel/actions/workflows/ci.yml/badge.svg)](https://github.com/haddowg/json-api-laravel/actions/workflows/ci.yml)
[![Latest Version](https://img.shields.io/packagist/v/haddowg/json-api-laravel.svg)](https://packagist.org/packages/haddowg/json-api-laravel)
[![PHP Version](https://img.shields.io/packagist/php-v/haddowg/json-api-laravel.svg)](https://packagist.org/packages/haddowg/json-api-laravel)
[![License](https://img.shields.io/packagist/l/haddowg/json-api-laravel.svg)](LICENSE)

> **Part of the [jsonapi.rest](https://jsonapi.rest) suite** — a complete, spec-compliant
> JSON:API 1.1 stack for PHP: a framework-agnostic [core](https://github.com/haddowg/json-api),
> a [Symfony bundle](https://github.com/haddowg/json-api-symfony), this **Laravel package**, and
> a typed TypeScript client, bound together by one conformance-tested OpenAPI 3.1 contract.

A Laravel package that makes [`haddowg/json-api`](https://github.com/haddowg/json-api)
idiomatic in a Laravel application: declare a JSON:API type as a class and get the standard
endpoint set — spec-compliant JSON:API 1.1 documents, content negotiation, validation, policy
authorization, and an **Eloquent data layer** — with no controller, handler, or serializer
wired by hand.

It is the Laravel twin of the [Symfony bundle](https://github.com/haddowg/json-api-symfony):
both build on the same framework-agnostic core and project a **byte-identical OpenAPI document**
for an identical domain, so a client generator consumes either backend unchanged.

## Requirements

- PHP `^8.3` (8.3 / 8.4 / 8.5)
- Laravel `^12.0 || ^13.0` (via the `illuminate/*` components)

## Install

```bash
composer require haddowg/json-api-laravel
php artisan vendor:publish --tag=jsonapi-config   # optional — customise servers, pagination, OpenAPI
```

The service provider is auto-discovered and core is pulled in transitively — there is nothing
to register by hand.

## Documentation

Full documentation is published at **[haddowg.github.io/json-api-laravel](https://haddowg.github.io/json-api-laravel/)**.
Start with [install](https://haddowg.github.io/json-api-laravel/install/) and
[getting started](https://haddowg.github.io/json-api-laravel/getting-started/), or browse the
[documentation index](https://haddowg.github.io/json-api-laravel/).

Core concepts (fields, relations, constraints, response value objects) live in the
[core documentation](https://haddowg.github.io/json-api/).

## Demo

`docker compose up` serves the full twelve-type music-catalog example over HTTP — browse
`http://localhost:8080/api/albums` and the interactive OpenAPI docs at
`http://localhost:8080/docs`. See the
[Docker guide](https://haddowg.github.io/json-api-laravel/docker/).

## License

Released under the [MIT License](LICENSE).
