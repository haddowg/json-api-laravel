# Keys compare in their stored form

A key behind a value-object cast reads as an object, while the database holds what the cast stores, so binding the object or comparing its string misses the row. Every query the data layer builds binds a key as the model stores it, through `StoredKey`, and every in-memory match compares stored values, and every stored id renders its wire id through `StoredIds`, so the conversion lives in two places rather than in each reader.
