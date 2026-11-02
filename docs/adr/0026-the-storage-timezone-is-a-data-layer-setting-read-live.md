# The storage timezone is a data-layer setting read live

`jsonapi.storage_timezone` names the zone the Eloquent data layer stores datetime-cast columns in, and servers cannot override it because the columns belong to the database, not to an API server. Both the setting and, when it is null, PHP's default timezone are read each time a value is converted rather than captured at boot, because multi-tenant applications switch the default per request.
