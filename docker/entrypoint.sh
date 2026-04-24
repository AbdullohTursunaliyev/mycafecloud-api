#!/bin/sh
set -e

cd /var/www/html

wait_for_db() {
  if [ -z "${DB_HOST:-}" ]; then
    return 0
  fi

  echo "Waiting for database at ${DB_HOST}:${DB_PORT:-5432}..."
  until nc -z "${DB_HOST}" "${DB_PORT:-5432}" >/dev/null 2>&1; do
    sleep 2
  done
}

wait_for_db

if [ -f artisan ]; then
  if [ ! -e public/storage ] && [ ! -L public/storage ]; then
    php artisan storage:link || true
  fi

  if [ "${RUN_MIGRATIONS:-true}" = "true" ]; then
    php artisan migrate --force || true
  fi
fi

if [ "$#" -eq 0 ]; then
  set -- sh -lc 'php artisan serve --host=0.0.0.0 --port="${PORT:-8080}"'
fi

exec "$@"
