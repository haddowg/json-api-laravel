# Serve the music-catalog workbench over `testbench serve` — a self-contained demo of the
# package answering real JSON:API requests over HTTP (PLAN Phase 5 docker item; parity with
# the Symfony example's `docker compose up`).
#
# Build + run from the repository root:
#   docker compose up            # then open http://localhost:8080/api/albums
# Or by hand:
#   docker build -t json-api-laravel-demo .
#   docker run --rm -p 8080:80 json-api-laravel-demo
#   curl -H 'Accept: application/vnd.api+json' http://localhost:8080/api/albums
FROM php:8.3-cli-bookworm

# intl backs the `countries` reference data (symfony/intl); pdo_sqlite backs the demo
# database; zip + unzip + git let Composer fetch the core + testbench stack.
RUN apt-get update \
 && apt-get install -y --no-install-recommends git unzip libicu-dev libzip-dev libonig-dev libsqlite3-dev \
 && docker-php-ext-install -j"$(nproc)" intl zip pdo_sqlite \
 && apt-get clean && rm -rf /var/lib/apt/lists/*

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /app
COPY . .

# Resolve the unpublished core (dev-main) from GitHub exactly as CI does — the committed lock
# is path-repo based, so the tree is re-resolved fresh. `"no-api":true` makes Composer clone
# core over git rather than walking the GitHub commits API (which trips the anonymous
# secondary rate limit — HTTP 429 — on dev-branch resolution). COMPOSER_MAX_PARALLEL_HTTP
# caps concurrent dist downloads so anonymous codeload fetches don't trip HTTP 400.
# Pass --build-arg GITHUB_TOKEN=… to authenticate the fetch and lift the anonymous limits.
ARG GITHUB_TOKEN=""
ENV COMPOSER_MAX_PARALLEL_HTTP=6
RUN if [ -n "$GITHUB_TOKEN" ]; then composer config -g github-oauth.github.com "$GITHUB_TOKEN"; fi \
 && composer config repositories.haddowg-json-api '{"type":"vcs","url":"https://github.com/haddowg/json-api","no-api":true}' \
 && composer update --no-interaction --prefer-dist --no-progress

# Boot the full music-catalog domain (not the default workbench suite) by swapping in the
# demo Testbench config, then build a FILE-backed SQLite so writes persist across the
# per-request dev-server boots (an in-memory DB would re-seed every request). The DB lives
# in the image's writable layer, so the demo "resets" on `docker run` again.
ENV DB_CONNECTION=sqlite
ENV DB_DATABASE=/app/database/demo.sqlite
RUN cp testbench.docker.yaml testbench.yaml \
 && mkdir -p /app/database && touch "$DB_DATABASE" && chmod -R 777 /app/database \
 && vendor/bin/testbench migrate --force \
 && vendor/bin/testbench db:seed --force --class='Workbench\Database\Seeders\McCatalogSeeder'

EXPOSE 80
CMD ["vendor/bin/testbench", "serve", "--host", "0.0.0.0", "--port", "80"]
