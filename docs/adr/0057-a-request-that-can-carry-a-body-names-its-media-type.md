# A request that can carry a body names its media type

Following core ADR 0172, every `POST` and any other write with a body must send a `Content-Type` the endpoint takes, or it is a `415`; before, only the parameters of a JSON:API media type were checked, so a body with no `Content-Type` or a plain `application/json` one was read as a document. An action names the other types it takes in `accepts`, each read in its own input mode, and takes the three form types a browser sends without a preflight only when it names them, so a raw action that takes `multipart/form-data` now has to list it.
