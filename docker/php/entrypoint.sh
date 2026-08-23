#!/bin/sh
set -e

cd /var/www/html

# Block until MySQL accepts TCP connections so the first `migrate` doesn't race
# the database container coming up.
echo "cipherlearn: waiting for database at ${DB_HOST}:${DB_PORT}..."
until php -r '$c=@fsockopen(getenv("DB_HOST"), (int) getenv("DB_PORT")); exit($c ? 0 : 1);' 2>/dev/null; do
    sleep 1
done
echo "cipherlearn: database is up."

# Generate an app key only if one hasn't been provided — via the APP_KEY env var
# (how production should supply it, as a secret) or already baked into .env. This
# guard keeps local/dev convenient without clobbering a provided key, and never
# rotates an existing one (which would invalidate sessions and encrypted values).
if [ -z "$APP_KEY" ] && ! grep -qE '^APP_KEY=base64:' .env 2>/dev/null; then
    echo "cipherlearn: generating APP_KEY..."
    php artisan key:generate --force
fi

# ── Release steps (idempotent — safe on every boot of the app container) ───────
php artisan migrate --force

# Roll the permission vocabulary + role grants out to EVERY tenant, so a release
# that adds a permission (e.g. audit.view) reaches all tenant admins — not just the
# demo tenant. No-op when nothing changed.
php artisan permissions:sync

# In production, cache config/routes/views for speed. Skipped in dev, where code
# and .env are bind-mounted and hot-reloaded (a cached config would go stale).
if [ "$APP_ENV" = "production" ]; then
    echo "cipherlearn: caching config, routes and views..."
    php artisan config:cache
    php artisan route:cache
    php artisan view:cache
fi

exec "$@"
