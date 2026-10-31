# Changelog

## [1.21.0](https://github.com/haddowg/json-api-laravel/compare/v1.20.0...v1.21.0) (2026-10-31)


### Features

* run entity-level constraints after relationship writes ([#178](https://github.com/haddowg/json-api-laravel/issues/178)) ([c56ab32](https://github.com/haddowg/json-api-laravel/commit/c56ab32896e368d4e11c413d6dcf40027f145baa))

## [1.20.0](https://github.com/haddowg/json-api-laravel/compare/v1.19.1...v1.20.0) (2026-10-28)


### Features

* let each server declare its own response-header defaults ([#175](https://github.com/haddowg/json-api-laravel/issues/175)) ([c8b2a12](https://github.com/haddowg/json-api-laravel/commit/c8b2a12ca6149dd0ac6b9bfe418c2514794c65b5))

## [1.19.1](https://github.com/haddowg/json-api-laravel/compare/v1.19.0...v1.19.1) (2026-10-28)


### Bug Fixes

* stop a batched read from emptying a relation it did not load ([#172](https://github.com/haddowg/json-api-laravel/issues/172)) ([99e3515](https://github.com/haddowg/json-api-laravel/commit/99e351562f7c7e0dd4f507ecd5d8ad8348e92200))

## [1.19.0](https://github.com/haddowg/json-api-laravel/compare/v1.18.2...v1.19.0) (2026-10-28)


### Features

* load an identified MorphTo's targets through each type's provider ([#171](https://github.com/haddowg/json-api-laravel/issues/171)) ([0e13c16](https://github.com/haddowg/json-api-laravel/commit/0e13c16acb730321717f7e3646f747a9c86d9a07))

## [1.18.2](https://github.com/haddowg/json-api-laravel/compare/v1.18.1...v1.18.2) (2026-10-27)


### Bug Fixes

* declare the atomic ext on an error only when the request named it ([#169](https://github.com/haddowg/json-api-laravel/issues/169)) ([4c74a75](https://github.com/haddowg/json-api-laravel/commit/4c74a75a16b0b791642c1671b0e156dd868deba4))

## [1.18.1](https://github.com/haddowg/json-api-laravel/compare/v1.18.0...v1.18.1) (2026-10-27)


### Bug Fixes

* declare the atomic ext on errors raised before the batch runs ([#167](https://github.com/haddowg/json-api-laravel/issues/167)) ([9fc8fcf](https://github.com/haddowg/json-api-laravel/commit/9fc8fcf341f0cfa59185ee1fecc884a3fea47923))

## [1.18.0](https://github.com/haddowg/json-api-laravel/compare/v1.17.1...v1.18.0) (2026-10-27)


### Features

* add a --compact option to the export commands ([#164](https://github.com/haddowg/json-api-laravel/issues/164)) ([4d3ac01](https://github.com/haddowg/json-api-laravel/commit/4d3ac015508a9fbc9d9ee321f418ac35d0f92200))

## [1.17.1](https://github.com/haddowg/json-api-laravel/compare/v1.17.0...v1.17.1) (2026-10-27)


### Bug Fixes

* render a 405 on a JSON:API path as a JSON:API error ([#162](https://github.com/haddowg/json-api-laravel/issues/162)) ([f34d43d](https://github.com/haddowg/json-api-laravel/commit/f34d43d7e4bb4078728ada5a9d137b614e4b8657))

## [1.17.0](https://github.com/haddowg/json-api-laravel/compare/v1.16.1...v1.17.0) (2026-10-27)


### Features

* render a resource's conditional action links ([#160](https://github.com/haddowg/json-api-laravel/issues/160)) ([8e39f1c](https://github.com/haddowg/json-api-laravel/commit/8e39f1cf73c96b9bcb5634e29e6b21b45929783b))

## [1.16.1](https://github.com/haddowg/json-api-laravel/compare/v1.16.0...v1.16.1) (2026-10-27)


### Bug Fixes

* build action links on the server's link base ([#158](https://github.com/haddowg/json-api-laravel/issues/158)) ([7b9c5f4](https://github.com/haddowg/json-api-laravel/commit/7b9c5f461cdd116aa454dd7cefeeb10a21778b20))

## [1.16.0](https://github.com/haddowg/json-api-laravel/compare/v1.15.2...v1.16.0) (2026-10-27)


### Features

* let a custom action answer with a URL ([#156](https://github.com/haddowg/json-api-laravel/issues/156)) ([03266d7](https://github.com/haddowg/json-api-laravel/commit/03266d756d168406c7ec096730fe56491eb07be3))

## [1.15.2](https://github.com/haddowg/json-api-laravel/compare/v1.15.1...v1.15.2) (2026-10-27)


### Bug Fixes

* list no include paths through a single-type morph relation ([#154](https://github.com/haddowg/json-api-laravel/issues/154)) ([e8b7d3a](https://github.com/haddowg/json-api-laravel/commit/e8b7d3ab560321b7ae15cb4c0224930b01b8760e))

## [1.15.1](https://github.com/haddowg/json-api-laravel/compare/v1.15.0...v1.15.1) (2026-10-27)


### Bug Fixes

* serve a single-type morph relation as polymorphic ([#152](https://github.com/haddowg/json-api-laravel/issues/152)) ([2f842cc](https://github.com/haddowg/json-api-laravel/commit/2f842ccfbbd1abebf4014ad1c720871de52db29b))

## [1.15.0](https://github.com/haddowg/json-api-laravel/compare/v1.14.0...v1.15.0) (2026-10-27)


### Features

* enforce, document and catalogue OAuth scopes per operation ([#150](https://github.com/haddowg/json-api-laravel/issues/150)) ([104b3e4](https://github.com/haddowg/json-api-laravel/commit/104b3e418170792ac4039bec56f8a5318923908e))

## [1.14.0](https://github.com/haddowg/json-api-laravel/compare/v1.13.0...v1.14.0) (2026-10-26)


### Features

* declare action safety and per-action extensions ([#148](https://github.com/haddowg/json-api-laravel/issues/148)) ([7336f9c](https://github.com/haddowg/json-api-laravel/commit/7336f9c86862ea62bc557269cbd8bb35ee71625f))

## [1.13.0](https://github.com/haddowg/json-api-laravel/compare/v1.12.0...v1.13.0) (2026-10-26)


### Features

* render and document declared resource links ([#146](https://github.com/haddowg/json-api-laravel/issues/146)) ([d284362](https://github.com/haddowg/json-api-laravel/commit/d284362f2cf9689bc10684f6e885039bb3dbd0c4))

## [1.12.0](https://github.com/haddowg/json-api-laravel/compare/v1.11.0...v1.12.0) (2026-10-26)


### Features

* show always-counted pages and relation example ids in examples ([#144](https://github.com/haddowg/json-api-laravel/issues/144)) ([06861f1](https://github.com/haddowg/json-api-laravel/commit/06861f13e47aace8fffe152b4cb8b33c78bad2fc))

## [1.11.0](https://github.com/haddowg/json-api-laravel/compare/v1.10.1...v1.11.0) (2026-10-26)


### Features

* generate OpenAPI operation examples from field examples ([#142](https://github.com/haddowg/json-api-laravel/issues/142)) ([1486b29](https://github.com/haddowg/json-api-laravel/commit/1486b29f726d30dc700f463cae7898ecdb39346d))

## [1.10.1](https://github.com/haddowg/json-api-laravel/compare/v1.10.0...v1.10.1) (2026-10-26)


### Bug Fixes

* require core 1.12.1 for correct text body schemas ([#140](https://github.com/haddowg/json-api-laravel/issues/140)) ([20fa0b2](https://github.com/haddowg/json-api-laravel/commit/20fa0b29a75bf4e31cb1a6dd9ca5bc428556f713))

## [1.10.0](https://github.com/haddowg/json-api-laravel/compare/v1.9.2...v1.10.0) (2026-10-26)


### Features

* add a binary UUID id encoder for Eloquent keys ([#127](https://github.com/haddowg/json-api-laravel/issues/127)) ([9f8286b](https://github.com/haddowg/json-api-laravel/commit/9f8286b363890d4a0e219da04880b206ce85b44e))
* add summary and description to custom actions ([#131](https://github.com/haddowg/json-api-laravel/issues/131)) ([9a60878](https://github.com/haddowg/json-api-laravel/commit/9a608783372fe924b4d7998084aeaae649aa13c0))
* check uniqueness among the rows the caller can see ([#138](https://github.com/haddowg/json-api-laravel/issues/138)) ([b2b36c0](https://github.com/haddowg/json-api-laravel/commit/b2b36c0a3fd44d37257d57fd52684864b4dce0a5))
* let a custom action answer with a raw response body ([#135](https://github.com/haddowg/json-api-laravel/issues/135)) ([7372227](https://github.com/haddowg/json-api-laravel/commit/7372227b7a14b5f8b37e22f96c3c860701c012bd))
* let a server refuse read-only attributes in write bodies ([#134](https://github.com/haddowg/json-api-laravel/issues/134)) ([ad331c3](https://github.com/haddowg/json-api-laravel/commit/ad331c3ef0e051d7bf3eaa0704434bcba23c97e6))
* let an app extend the Eloquent persister ([#130](https://github.com/haddowg/json-api-laravel/issues/130)) ([be105b5](https://github.com/haddowg/json-api-laravel/commit/be105b5a0e7dc4d51a699e689b310c4ad8a3c24a))
* project accepted request bodies through core ([#137](https://github.com/haddowg/json-api-laravel/issues/137)) ([d3d3aa6](https://github.com/haddowg/json-api-laravel/commit/d3d3aa6f9a7b7769989a56df82e09a3b4cca0d7f))


### Bug Fixes

* require haddowg/json-api 1.11 ([#132](https://github.com/haddowg/json-api-laravel/issues/132)) ([942c5e4](https://github.com/haddowg/json-api-laravel/commit/942c5e48ebb708d9137fdef8ffe7641f77f5cf0b))
* require the JSON:API content type on writes ([#133](https://github.com/haddowg/json-api-laravel/issues/133)) ([9cd8f35](https://github.com/haddowg/json-api-laravel/commit/9cd8f353ec143bf0a37114f70c1853dee502ac5f))
* take a bodyless JSON:API POST on any action ([#136](https://github.com/haddowg/json-api-laravel/issues/136)) ([abae531](https://github.com/haddowg/json-api-laravel/commit/abae5311e0829b429681a0ffef4ec8752ace352a))
* write a relation through its fillUsing hook ([#128](https://github.com/haddowg/json-api-laravel/issues/128)) ([aaa5430](https://github.com/haddowg/json-api-laravel/commit/aaa5430b90a0923c211c5c93ef8a618d8fcf5b29))

## [1.9.2](https://github.com/haddowg/json-api-laravel/compare/v1.9.1...v1.9.2) (2026-10-25)


### Bug Fixes

* rescan discovery when optimize rewrites the snapshot ([#126](https://github.com/haddowg/json-api-laravel/issues/126)) ([8e19e26](https://github.com/haddowg/json-api-laravel/commit/8e19e26ad3fe72d9944e7c0d800834f1021566ec))
* store the resolved model map in the discovery snapshot ([#124](https://github.com/haddowg/json-api-laravel/issues/124)) ([aa4ccb3](https://github.com/haddowg/json-api-laravel/commit/aa4ccb3d0c11aa739b42fa1b2b5ba54721bb4bfe))

## [1.9.1](https://github.com/haddowg/json-api-laravel/compare/v1.9.0...v1.9.1) (2026-10-25)


### Bug Fixes

* decode wire ids with the dispatching server's id encoder ([#123](https://github.com/haddowg/json-api-laravel/issues/123)) ([2253a5e](https://github.com/haddowg/json-api-laravel/commit/2253a5e0026b18fc2c15bfa2fa0c07ef7ea1f4dc))
* key authorization by server and type ([#120](https://github.com/haddowg/json-api-laravel/issues/120)) ([530375c](https://github.com/haddowg/json-api-laravel/commit/530375c106aa6f87cfd55f0cbf1186faf42c8323))
* key response headers and action tags by server and type ([#122](https://github.com/haddowg/json-api-laravel/issues/122)) ([8d9982d](https://github.com/haddowg/json-api-laravel/commit/8d9982da17d0edf838b3a15a047cdc2f019c64c6))

## [1.9.0](https://github.com/haddowg/json-api-laravel/compare/v1.8.2...v1.9.0) (2026-10-25)


### Features

* authorize the relation data core renders and flattened writes ([#109](https://github.com/haddowg/json-api-laravel/issues/109)) ([080d479](https://github.com/haddowg/json-api-laravel/commit/080d4793d7cef5ebece75ffca764f8350266eb65))
* let a relation declare its own read permission ([#115](https://github.com/haddowg/json-api-laravel/issues/115)) ([fc7a34a](https://github.com/haddowg/json-api-laravel/commit/fc7a34a6fbf0a4cfe0dd154c3aed19397254ae36))
* narrow a polymorphic to-many per member type instead of refusing it ([#119](https://github.com/haddowg/json-api-laravel/issues/119)) ([fd65f4a](https://github.com/haddowg/json-api-laravel/commit/fd65f4ada11baca7e0596f72ae7096e7c1c1e6f2))


### Bug Fixes

* answer 404 at the endpoints of a relation hidden from the caller ([#107](https://github.com/haddowg/json-api-laravel/issues/107)) ([56ee98a](https://github.com/haddowg/json-api-laravel/commit/56ee98a1a116dda05f8e3f5a90bb3786681d714c))
* apply relation queries to a to-one related resource's relations ([#118](https://github.com/haddowg/json-api-laravel/issues/118)) ([5e778fc](https://github.com/haddowg/json-api-laravel/commit/5e778fc2a9b8026285e88163f5d55c0dff020647))
* check the update gate on the stored and the changed resource ([#111](https://github.com/haddowg/json-api-laravel/issues/111)) ([3b93be2](https://github.com/haddowg/json-api-laravel/commit/3b93be20e9f8f17a1329e6df180777ba82eb516b))
* count a computed to-many on the Eloquent provider ([#116](https://github.com/haddowg/json-api-laravel/issues/116)) ([ff2475e](https://github.com/haddowg/json-api-laravel/commit/ff2475e6ab211807d65e52c79092fb799e03fd54))
* gate related lists by their type's list check, not per row ([#114](https://github.com/haddowg/json-api-laravel/issues/114)) ([9f87029](https://github.com/haddowg/json-api-laravel/commit/9f87029a6d369ece728b462bf409adf10e456909))
* hide a related row its own type's view policy refuses ([#110](https://github.com/haddowg/json-api-laravel/issues/110)) ([60610e7](https://github.com/haddowg/json-api-laravel/commit/60610e78e5156967f8da658e62fac7244abd7bf3))
* **openapi:** count any JSON:API route when deciding a server is mounted ([#113](https://github.com/haddowg/json-api-laravel/issues/113)) ([3d1cd8e](https://github.com/haddowg/json-api-laravel/commit/3d1cd8e38aa48f2878a5e06fed15e84d17237e74))
* require core 1.9 so read-only relations refuse relationship writes ([#106](https://github.com/haddowg/json-api-laravel/issues/106)) ([2adf41d](https://github.com/haddowg/json-api-laravel/commit/2adf41d914e2779bffaaf29a1607d66ab73b6efc))
* require update on a row no parent holds before linking it ([#112](https://github.com/haddowg/json-api-laravel/issues/112)) ([0e4c56f](https://github.com/haddowg/json-api-laravel/commit/0e4c56fa2973d6b77563f1ff33ef314887f6c377))
* window an included computed to-many in PHP on Eloquent ([#117](https://github.com/haddowg/json-api-laravel/issues/117)) ([8bbfada](https://github.com/haddowg/json-api-laravel/commit/8bbfadacf3892a3ad5e43b5b800b5bc08aec2c64))

## [1.8.2](https://github.com/haddowg/json-api-laravel/compare/v1.8.1...v1.8.2) (2026-10-24)


### Bug Fixes

* apply a relation's mutate ability to embedded relationship writes ([#103](https://github.com/haddowg/json-api-laravel/issues/103)) ([e1f107a](https://github.com/haddowg/json-api-laravel/commit/e1f107a30273019021c647f350d564ed1135d61f))
* check the current parent before moving a has-many row ([#105](https://github.com/haddowg/json-api-laravel/issues/105)) ([b38d6ca](https://github.com/haddowg/json-api-laravel/commit/b38d6ca48dfd25a8027b54ed5274fd8b7ff044d5))
* **openapi:** only warn about a missing docs route for servers that serve API routes ([#101](https://github.com/haddowg/json-api-laravel/issues/101)) ([79614a6](https://github.com/haddowg/json-api-laravel/commit/79614a6a58abac309a81d4332ea23daa8d2872e4))
* scope Atomic Operations to the batch's own server ([#104](https://github.com/haddowg/json-api-laravel/issues/104)) ([1dcff6c](https://github.com/haddowg/json-api-laravel/commit/1dcff6cd859912ca6695c8d5347d166a19e14e0e))

## [1.8.1](https://github.com/haddowg/json-api-laravel/compare/v1.8.0...v1.8.1) (2026-10-24)


### Bug Fixes

* **openapi:** find a custom docs route for describedby and warn when none serves the document ([#99](https://github.com/haddowg/json-api-laravel/issues/99)) ([bf6215c](https://github.com/haddowg/json-api-laravel/commit/bf6215c1be799dec8cfea49a24b4a50c719339f9))

## [1.8.0](https://github.com/haddowg/json-api-laravel/compare/v1.7.2...v1.8.0) (2026-10-24)


### Features

* **openapi:** default the ReDoc viewer to 3.0.1 with telemetry off ([2fe9459](https://github.com/haddowg/json-api-laravel/commit/2fe94599fedad4287a4e299e6911276fef343642))
* **openapi:** let a resource declare its singular noun ([2fe9459](https://github.com/haddowg/json-api-laravel/commit/2fe94599fedad4287a4e299e6911276fef343642))


### Bug Fixes

* **testing:** follow an allOf-wrapped reference when relaxing a fieldset ([2fe9459](https://github.com/haddowg/json-api-laravel/commit/2fe94599fedad4287a4e299e6911276fef343642))

## [1.7.2](https://github.com/haddowg/json-api-laravel/compare/v1.7.1...v1.7.2) (2026-10-24)


### Bug Fixes

* reject an empty string wherever a minimum length or pattern is declared ([ca8de78](https://github.com/haddowg/json-api-laravel/commit/ca8de78cef1d841b78466cdccb0b6d04c21d83de))

## [1.7.1](https://github.com/haddowg/json-api-laravel/compare/v1.7.0...v1.7.1) (2026-10-24)


### Bug Fixes

* reject an empty string on a minLength attribute ([3b16ff3](https://github.com/haddowg/json-api-laravel/commit/3b16ff3e18df561429e654319d18da0a1a3fbb23))


### Miscellaneous Chores

* **example:** mirror the renamed public-owner relation and artist slug rules ([3b16ff3](https://github.com/haddowg/json-api-laravel/commit/3b16ff3e18df561429e654319d18da0a1a3fbb23))

## [1.7.0](https://github.com/haddowg/json-api-laravel/compare/v1.6.1...v1.7.0) (2026-10-24)


### Features

* adopt core generator contract 8 ([37c35d9](https://github.com/haddowg/json-api-laravel/commit/37c35d926ccc0b277b2cc0a3df2f0b00f421114b))


### Bug Fixes

* page and query a pivot relationship endpoint like the related endpoint ([#90](https://github.com/haddowg/json-api-laravel/issues/90)) ([4597d67](https://github.com/haddowg/json-api-laravel/commit/4597d6746c6e2d5dd4a92703e2ad772b1ad08c5a))
* render pivot dates in their field's format ([37c35d9](https://github.com/haddowg/json-api-laravel/commit/37c35d926ccc0b277b2cc0a3df2f0b00f421114b))

## [1.6.1](https://github.com/haddowg/json-api-laravel/compare/v1.6.0...v1.6.1) (2026-10-24)


### Bug Fixes

* leave write-only pivot fields out of responses ([#88](https://github.com/haddowg/json-api-laravel/issues/88)) ([56915de](https://github.com/haddowg/json-api-laravel/commit/56915de66f445b6bc07b3fdc1e8c7cee8905ba3f))

## [1.6.0](https://github.com/haddowg/json-api-laravel/compare/v1.5.2...v1.6.0) (2026-10-24)


### Features

* **openapi:** declare vendor extensions on a standalone serializer ([#86](https://github.com/haddowg/json-api-laravel/issues/86)) ([5781c4f](https://github.com/haddowg/json-api-laravel/commit/5781c4f1773b0144b6740f60ac422087e8151ba4))


### Bug Fixes

* page a to-many relationship endpoint by default ([#85](https://github.com/haddowg/json-api-laravel/issues/85)) ([d21b1c6](https://github.com/haddowg/json-api-laravel/commit/d21b1c68b9391139a1eda349a2f3814857b63678))

## [1.5.2](https://github.com/haddowg/json-api-laravel/compare/v1.5.1...v1.5.2) (2026-10-24)


### Bug Fixes

* persist a write through a flattened attribute to its related model ([#66](https://github.com/haddowg/json-api-laravel/issues/66)) ([60d32ca](https://github.com/haddowg/json-api-laravel/commit/60d32cab19d3824e63d1e1686ba2debad7f5985a))

## [1.5.1](https://github.com/haddowg/json-api-laravel/compare/v1.5.0...v1.5.1) (2026-10-24)


### Bug Fixes

* **openapi:** apply cache_control to the viewer page ([#82](https://github.com/haddowg/json-api-laravel/issues/82)) ([3e32085](https://github.com/haddowg/json-api-laravel/commit/3e3208581e0f781551cd01d1d2abe5c0f0c6a509))

## [1.5.0](https://github.com/haddowg/json-api-laravel/compare/v1.4.0...v1.5.0) (2026-10-24)


### Features

* **openapi:** customise the served document per request ([#79](https://github.com/haddowg/json-api-laravel/issues/79)) ([5def6b8](https://github.com/haddowg/json-api-laravel/commit/5def6b8c6d9d59216ca01a02823206356c8f324b))
* point built links at their operation in the served OpenAPI document ([944081a](https://github.com/haddowg/json-api-laravel/commit/944081a0f569add983ce227556d3cf6522916485))

## [1.4.0](https://github.com/haddowg/json-api-laravel/compare/v1.3.3...v1.4.0) (2026-10-24)


### Features

* declare OpenAPI vendor extensions on a resource ([38132c6](https://github.com/haddowg/json-api-laravel/commit/38132c6d649901452d76a2b2e2271545b2e925e6))


### Bug Fixes

* describe a related collection's page with the paginator serving it ([#74](https://github.com/haddowg/json-api-laravel/issues/74)) ([cdeae48](https://github.com/haddowg/json-api-laravel/commit/cdeae480336b8e84729f81b044db66db2410163c))
* let a required nullable field accept an explicit null on update ([#70](https://github.com/haddowg/json-api-laravel/issues/70)) ([fd064a7](https://github.com/haddowg/json-api-laravel/commit/fd064a7e59c4927ebc587483d1ba358ad60825a0))
* never read a write-only relation ([#72](https://github.com/haddowg/json-api-laravel/issues/72)) ([ba946ae](https://github.com/haddowg/json-api-laravel/commit/ba946aedeb9936fe5028ee305b5652b6dbb9aead))

## [1.3.3](https://github.com/haddowg/json-api-laravel/compare/v1.3.2...v1.3.3) (2026-10-24)


### Bug Fixes

* enforce a declared ability even when nothing defines it ([#73](https://github.com/haddowg/json-api-laravel/issues/73)) ([1427577](https://github.com/haddowg/json-api-laravel/commit/14275779699c9facf696b5ccb170e5090ad1c113))


### Miscellaneous Chores

* **example:** require a track number on create ([#76](https://github.com/haddowg/json-api-laravel/issues/76)) ([01c6119](https://github.com/haddowg/json-api-laravel/commit/01c611902714c19f9c6b81a83ee00b8e04f37118))
* **example:** require a track's genres on create ([#77](https://github.com/haddowg/json-api-laravel/issues/77)) ([d736e5c](https://github.com/haddowg/json-api-laravel/commit/d736e5c5cef6f6acea1720d7ea957f850ebe2a64))
* mark the example's always-set attributes guaranteed ([#69](https://github.com/haddowg/json-api-laravel/issues/69)) ([b94c870](https://github.com/haddowg/json-api-laravel/commit/b94c87015c4f7523ab7f428660146f81bc81f913))

## [1.3.2](https://github.com/haddowg/json-api-laravel/compare/v1.3.1...v1.3.2) (2026-10-23)


### Bug Fixes

* resolve related ids in writes through the related type's scope ([#67](https://github.com/haddowg/json-api-laravel/issues/67)) ([239a19f](https://github.com/haddowg/json-api-laravel/commit/239a19f05fc45cb09a8f213c4f2c037a78f5c7f5))

## [1.3.1](https://github.com/haddowg/json-api-laravel/compare/v1.3.0...v1.3.1) (2026-10-23)


### Bug Fixes

* load rendered members of a scoped related type through its provider ([3f7d573](https://github.com/haddowg/json-api-laravel/commit/3f7d573367c407132b37afb36e929cb014eb4a13))
* render a to-one target only while its own provider serves it ([3f7d573](https://github.com/haddowg/json-api-laravel/commit/3f7d573367c407132b37afb36e929cb014eb4a13))


### Miscellaneous Chores

* **deps:** require haddowg/json-api ^1.4 ([3f7d573](https://github.com/haddowg/json-api-laravel/commit/3f7d573367c407132b37afb36e929cb014eb4a13))

## [1.3.0](https://github.com/haddowg/json-api-laravel/compare/v1.2.0...v1.3.0) (2026-10-23)


### Features

* give 401 and 403 errors their stable codes ([cf3b88f](https://github.com/haddowg/json-api-laravel/commit/cf3b88f5ec63da5d4f1127b85258345a09093eb6))
* render date-time attributes in a configured output timezone ([cf3b88f](https://github.com/haddowg/json-api-laravel/commit/cf3b88f5ec63da5d4f1127b85258345a09093eb6))
* **testing:** validate included resources against their own type's schema ([fd581b0](https://github.com/haddowg/json-api-laravel/commit/fd581b0945558e7e6f2bcbbb302dbc2c18f8bf5c))


### Bug Fixes

* accept {} for an attribute whose shape requires an object ([e898083](https://github.com/haddowg/json-api-laravel/commit/e898083abc04dde69a160eb3a029686906fd1d13))
* give each server its own instance of a shared resource ([cf3b88f](https://github.com/haddowg/json-api-laravel/commit/cf3b88f5ec63da5d4f1127b85258345a09093eb6))
* keep a stored empty map from failing a partial update ([e898083](https://github.com/haddowg/json-api-laravel/commit/e898083abc04dde69a160eb3a029686906fd1d13))
* keep empty maps as objects in atomic:results ([e898083](https://github.com/haddowg/json-api-laravel/commit/e898083abc04dde69a160eb3a029686906fd1d13))
* read JSON:API query parameters from the raw query string ([54edb82](https://github.com/haddowg/json-api-laravel/commit/54edb82ff089e99d75887cd1ab0dc4295f6e6a08))
* read JSON:API request bodies from the raw content ([f4dcdbd](https://github.com/haddowg/json-api-laravel/commit/f4dcdbd43f154fe9952c8063988b5f5cb9a6ef7e))
* **testing:** accept sparse fieldset responses in schema conformance ([fd581b0](https://github.com/haddowg/json-api-laravel/commit/fd581b0945558e7e6f2bcbbb302dbc2c18f8bf5c))
* **testing:** fill in schema conformance failure messages ([f4dcdbd](https://github.com/haddowg/json-api-laravel/commit/f4dcdbd43f154fe9952c8063988b5f5cb9a6ef7e))


### Miscellaneous Chores

* **deps:** require haddowg/json-api ^1.2 ([cf3b88f](https://github.com/haddowg/json-api-laravel/commit/cf3b88f5ec63da5d4f1127b85258345a09093eb6))
* **deps:** require haddowg/json-api ^1.2.1 ([e898083](https://github.com/haddowg/json-api-laravel/commit/e898083abc04dde69a160eb3a029686906fd1d13))

## [1.2.0](https://github.com/haddowg/json-api-laravel/compare/v1.1.0...v1.2.0) (2026-10-22)


### Features

* describe the JSON:API route a request matched ([#55](https://github.com/haddowg/json-api-laravel/issues/55)) ([970ed53](https://github.com/haddowg/json-api-laravel/commit/970ed53478e61f40408a59494ca1479ca436607c))

## [1.1.0](https://github.com/haddowg/json-api-laravel/compare/v1.0.0...v1.1.0) (2026-10-22)


### Features

* **openapi:** advertise a server's route prefix in servers ([5ec7c5a](https://github.com/haddowg/json-api-laravel/commit/5ec7c5a309f8e7bc8e8c54d8d55a9c3726a4dacb))
* **openapi:** document the application's own error codes ([e98d718](https://github.com/haddowg/json-api-laravel/commit/e98d7185fca17a5cfe8cf1ee8c46c49a38f1d680))


### Bug Fixes

* base an empty base_uri on the server's route prefix ([5ec7c5a](https://github.com/haddowg/json-api-laravel/commit/5ec7c5a309f8e7bc8e8c54d8d55a9c3726a4dacb))
* **openapi:** register every type a related endpoint returns ([e98d718](https://github.com/haddowg/json-api-laravel/commit/e98d7185fca17a5cfe8cf1ee8c46c49a38f1d680))


### Miscellaneous Chores

* add openapi and atomic-operations to composer keywords ([#48](https://github.com/haddowg/json-api-laravel/issues/48)) ([11f7065](https://github.com/haddowg/json-api-laravel/commit/11f70655efd7bff4776545ef363458cc67dc3c2f))
* **deps:** require haddowg/json-api ^1.1.1 ([5ec7c5a](https://github.com/haddowg/json-api-laravel/commit/5ec7c5a309f8e7bc8e8c54d8d55a9c3726a4dacb))

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
