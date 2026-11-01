#!/bin/sh
# Prepares the app on every start: database, OAuth keys and caches. Safe to run repeatedly.
set -e
cd /app

if [ -z "$APP_KEY" ]; then
  echo "APP_KEY is not set. Generate one with: docker run --rm IMAGE php artisan key:generate --show" >&2
  exit 1
fi

if [ "$DB_CONNECTION" = "sqlite" ] && [ ! -f "$DB_DATABASE" ]; then
  touch "$DB_DATABASE"
fi

# Passport signs access tokens with these keys. They live on the data volume so tokens survive restarts.
if [ -z "$PASSPORT_PRIVATE_KEY" ] && [ ! -f "$EA_MCP_DATA_DIR/oauth-private.key" ]; then
  php artisan passport:keys --force --no-interaction >/dev/null
  mv storage/oauth-private.key storage/oauth-public.key "$EA_MCP_DATA_DIR/"
fi
if [ -z "$PASSPORT_PRIVATE_KEY" ]; then
  ln -sf "$EA_MCP_DATA_DIR/oauth-private.key" storage/oauth-private.key
  ln -sf "$EA_MCP_DATA_DIR/oauth-public.key" storage/oauth-public.key
fi

php artisan migrate --force --no-interaction
php artisan config:cache >/dev/null
php artisan route:cache >/dev/null
php artisan view:cache >/dev/null

exec "$@"
