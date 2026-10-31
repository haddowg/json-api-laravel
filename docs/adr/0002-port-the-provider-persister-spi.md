# Data access goes through a Provider/Persister SPI with Eloquent as the reference implementation

Core owns no data layer, so this package ports the Symfony bundle's per-type `DataProvider`/`DataPersister` SPI, resolved by the first `supports()` in descending priority, rather than letting a resource talk to Eloquent directly. The engine stays storage-agnostic, an in-memory provider can referee every Eloquent result, and the projected OpenAPI document stays byte-compatible with the bundle's. A type's model comes from explicit wiring, else `#[AsJsonApiResource(model:)]`, else a convention guess under `jsonapi.eloquent.model_namespace`; the last two only build the map injected into one auto-registered reference pair at priority `-256`, below a hand-wired reference pair (`-128`) and any application provider (`0`).

## Considered options

- **A resource declares `$model` and the engine queries Eloquent directly.** The smallest and most Laravel-looking design, rejected because it welds the CRUD engine to Eloquent, excludes every other store and any test without a database, and removes the dual-provider conformance witness.
