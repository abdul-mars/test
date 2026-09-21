#!/bin/sh
set -e

# Applying migrations at boot keeps a redeploy to a single step. The
# migration runner is forward-only and idempotent, so repeating it is safe.
echo "[qroute] applying migrations"
php /var/www/qroute/bin/console migrate

if [ -n "${DB_PATH:-}" ] && [ -f "$DB_PATH" ]; then
  chown www-data:www-data "$DB_PATH" || true
fi

exec "$@"
