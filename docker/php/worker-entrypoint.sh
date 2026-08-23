#!/bin/sh
set -e

cd /var/www/html

# Entrypoint for the scheduler and queue-worker containers. Unlike the app
# entrypoint, these MUST NOT run migrations, permission sync, or config caching —
# the app container owns the release steps, and having every worker also migrate
# would race the app on first boot. Workers only wait for the database to be
# reachable, then exec their long-running command.
echo "cipherlearn worker: waiting for database at ${DB_HOST}:${DB_PORT}..."
until php -r '$c=@fsockopen(getenv("DB_HOST"), (int) getenv("DB_PORT")); exit($c ? 0 : 1);' 2>/dev/null; do
    sleep 1
done
echo "cipherlearn worker: database is up."

exec "$@"
