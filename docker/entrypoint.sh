#!/bin/sh
set -e

cd /var/www/html

wait_for_db() {
  if [ -z "${DB_HOST:-}" ]; then
    return 0
  fi

  echo "Waiting for database at ${DB_HOST}:${DB_PORT:-5432}..."
  until nc -z "${DB_HOST}" "${DB_PORT:-5432}"; do
    sleep 2
  done
}

wait_for_db

if [ -f .env ]; then
  php artisan key:generate --force || true
  php artisan storage:link || true

  if [ "${RUN_MIGRATIONS:-true}" = "true" ]; then
    php artisan migrate --force || true
  fi
fi

exec "$@"
