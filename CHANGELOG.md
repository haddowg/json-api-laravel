# Changelog

## 1.0.0 (2026-07-13)


### Features

* add serializer/hydrator override params to #[AsJsonApiResource] ([#12](https://github.com/haddowg/json-api-laravel/issues/12)) ([6637a7f](https://github.com/haddowg/json-api-laravel/commit/6637a7f3186435f658cd90037882d22513217bbf))
* async write seam (202 Accepted / 303 See Other) ([#22](https://github.com/haddowg/json-api-laravel/issues/22)) ([5efb5a3](https://github.com/haddowg/json-api-laravel/commit/5efb5a3599b2a2e1abb6ea270870ad4ee73bee42))
* cursor (keyset) pagination on related-collection endpoints ([#15](https://github.com/haddowg/json-api-laravel/issues/15)) ([cd724e2](https://github.com/haddowg/json-api-laravel/commit/cd724e29ea0f3a338d5218ca64a2f8233c455b89))
* decode encoded resource ids on read and linkage writes (Eloquent) ([#13](https://github.com/haddowg/json-api-laravel/issues/13)) ([3b9f51d](https://github.com/haddowg/json-api-laravel/commit/3b9f51d64bf301b285567fcb96ba090b65744723))
* default-register the built-in profiles and make the set the full configurable list ([#38](https://github.com/haddowg/json-api-laravel/issues/38)) ([dba62a2](https://github.com/haddowg/json-api-laravel/commit/dba62a25b47d79550cfee4c7a75b6546cc270cb6))
* first-class soft deletes for the Eloquent reference layer ([#29](https://github.com/haddowg/json-api-laravel/issues/29)) ([39ea7f9](https://github.com/haddowg/json-api-laravel/commit/39ea7f93eb4f20858625cbaaaaf9b24d7c00e1a1))
* full domain parity, OpenAPI byte-compatibility, docs, and demo (phase 5) ([#7](https://github.com/haddowg/json-api-laravel/issues/7)) ([1db0699](https://github.com/haddowg/json-api-laravel/commit/1db06992eac70b46d61e474e0ba125ac8f7bcd8c))
* full reads on Eloquent with dual-provider conformance (phase 1) ([#3](https://github.com/haddowg/json-api-laravel/issues/3)) ([1d39352](https://github.com/haddowg/json-api-laravel/commit/1d39352c6efef7d82c3dcd1b978478447b1c298f))
* localize the error catalogue via the Laravel translator ([#31](https://github.com/haddowg/json-api-laravel/issues/31)) ([b64ce65](https://github.com/haddowg/json-api-laravel/commit/b64ce65e51e38daa8d403abc974a43a3a058e4c5))
* native Laravel rule and self-applying Eloquent filter carriers ([#27](https://github.com/haddowg/json-api-laravel/issues/27)) ([e4993c3](https://github.com/haddowg/json-api-laravel/commit/e4993c3f559390809b081bf5cdef065bf6a06d3f))
* OpenAPI, actions, atomic operations, events, headers, and testing kit (phase 4) ([#6](https://github.com/haddowg/json-api-laravel/issues/6)) ([a482934](https://github.com/haddowg/json-api-laravel/commit/a4829345f71c2cccd22971cabe44410db3fd3169))
* package skeleton and in-memory read path (phase 0) ([#1](https://github.com/haddowg/json-api-laravel/issues/1)) ([1c02c92](https://github.com/haddowg/json-api-laravel/commit/1c02c92b9b75827b7358900c842806731af00659))
* per-operation OpenAPI response declarations ([#28](https://github.com/haddowg/json-api-laravel/issues/28)) ([db8db40](https://github.com/haddowg/json-api-laravel/commit/db8db4090cba1790d512ab39a767e4d7b0702b36))
* pivot-related and linkage cursor pages via core's hoisted keyset ([#16](https://github.com/haddowg/json-api-laravel/issues/16)) ([21c55ff](https://github.com/haddowg/json-api-laravel/commit/21c55ff7a43bb5aee059b8766c42ac2c89a873bd))
* relationships — reads, mutations, pivots, and windowed queries (phase 3) ([#5](https://github.com/haddowg/json-api-laravel/issues/5)) ([6a6c4a8](https://github.com/haddowg/json-api-laravel/commit/6a6c4a820926644d82bb49fbaf71770ffaffc1ba))
* render cursor pagination on batched includes ([#35](https://github.com/haddowg/json-api-laravel/issues/35)) ([31b1499](https://github.com/haddowg/json-api-laravel/commit/31b14999dbb3d4c9bb9a8d882576bbac20300326))
* standalone #[AsJsonApiHydrator] for a type without a resource ([#19](https://github.com/haddowg/json-api-laravel/issues/19)) ([cbb24da](https://github.com/haddowg/json-api-laravel/commit/cbb24da5c432e7dba47e22834d87532f83fa9c89))
* support client-selectable pagination menus ([#34](https://github.com/haddowg/json-api-laravel/issues/34)) ([24cc3d1](https://github.com/haddowg/json-api-laravel/commit/24cc3d13a51d4c4f1ce6d5b8792747bcc30ea790))
* three-tier resource-to-model mapping with an auto-registered Eloquent pair ([#20](https://github.com/haddowg/json-api-laravel/issues/20)) ([6aa50c0](https://github.com/haddowg/json-api-laravel/commit/6aa50c094730199b8e48c3559f8bc07d3afae391))
* validate composite attribute types (Obj, OneOf, Shape) ([#9](https://github.com/haddowg/json-api-laravel/issues/9)) ([2893a6a](https://github.com/haddowg/json-api-laravel/commit/2893a6afa5658f62ecf803876d246334b2c21c9b))
* **workbench:** port the bundle's cross-cutting AuditLogSubscriber (parity F2) ([#18](https://github.com/haddowg/json-api-laravel/issues/18)) ([b47b032](https://github.com/haddowg/json-api-laravel/commit/b47b0321714b919c5abf0e6536b9988cab141794))
* writes, always-on validation, and policy authorization (phase 2) ([#4](https://github.com/haddowg/json-api-laravel/issues/4)) ([8e2c35e](https://github.com/haddowg/json-api-laravel/commit/8e2c35e54895d87ee3a25139c021af1e27b9e8ea))


### Bug Fixes

* include standalone-serializer types in schema export and warmer ([#11](https://github.com/haddowg/json-api-laravel/issues/11)) ([4ace1d5](https://github.com/haddowg/json-api-laravel/commit/4ace1d5a6c76aa48ac13792f08aec0429719c2a4))
* **warmer:** stop false-flagging extractUsing/storedAs relations ([#17](https://github.com/haddowg/json-api-laravel/issues/17)) ([c892dfc](https://github.com/haddowg/json-api-laravel/commit/c892dfc12096b66bc06e0e1bae759e1156cd95c3))


### Performance Improvements

* collapse cursor-resolved includes to a single window query ([#39](https://github.com/haddowg/json-api-laravel/issues/39)) ([51da684](https://github.com/haddowg/json-api-laravel/commit/51da68415b8d1690edf08f2fa839e76b871df0de))


### Miscellaneous Chores

* close the consistency gaps from the bundle-merge parity audit ([#8](https://github.com/haddowg/json-api-laravel/issues/8)) ([c7b5395](https://github.com/haddowg/json-api-laravel/commit/c7b5395c666890b05fa63f767f84513daa5ed0fb))
* exclude non-runtime files from the Composer dist archive ([#45](https://github.com/haddowg/json-api-laravel/issues/45)) ([307d694](https://github.com/haddowg/json-api-laravel/commit/307d6941f452b431f0700ac68742faf4fb3148a3))
* remove build plan and prepare docs for release ([#44](https://github.com/haddowg/json-api-laravel/issues/44)) ([48cb883](https://github.com/haddowg/json-api-laravel/commit/48cb8830769e2c28c940353f6efba6bae7740991))
* scaffold repo ([d5309d5](https://github.com/haddowg/json-api-laravel/commit/d5309d5bf3ca9e6ad0c5d899ae320d60fe8fb020))
* tag releases as v-prefixed versions for Packagist ([#46](https://github.com/haddowg/json-api-laravel/issues/46)) ([814459b](https://github.com/haddowg/json-api-laravel/commit/814459b2e38d9d91efe86db50a32441759eb68fd))


### Build System

* depend on the published core release (^1.0) ([#47](https://github.com/haddowg/json-api-laravel/issues/47)) ([2938424](https://github.com/haddowg/json-api-laravel/commit/293842413a1bd3c8624c0ac4ac3a2a0e992b5f98))
