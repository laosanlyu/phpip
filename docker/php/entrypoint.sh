#!/bin/sh
set -e

# Wait for MySQL to be ready
echo "Waiting for MySQL to be ready..."
until php artisan db:show 2>/dev/null; do
  echo "MySQL is unavailable - sleeping"
  sleep 2
done

echo "MySQL is up - executing command"

# Run migrations (--force allows running in production without prompt)
# The migrate command is idempotent and will skip already-run migrations
echo "Running database migrations..."
php artisan migrate --force || echo "Migrations may have already been run or there was an issue"
echo "Migrations completed"

# Clear and cache configuration
echo "Optimizing application..."
php artisan config:cache
php artisan route:cache
php artisan view:cache

# Handle different container roles
if [ "$CONTAINER_ROLE" = "scheduler" ]; then
    echo "Running as scheduler..."
    exec "$@"
elif [ "$CONTAINER_ROLE" = "queue" ]; then
    echo "Running as queue worker..."
    exec php artisan queue:work --tries=3 --timeout=90
else
    echo "Running as application server..."
    exec "$@"
fi
