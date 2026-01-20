#!/bin/sh
set -e

# ============================================
# Step 1: Check and install dependencies
# ============================================
if [ ! -d "vendor" ] || [ ! -f "vendor/autoload.php" ]; then
    echo "Installing PHP dependencies..."
    composer install --no-dev --no-interaction --optimize-autoloader
fi

# ============================================
# Step 2: Generate APP_KEY if not set
# ============================================
if [ -z "$APP_KEY" ] && grep -q "^APP_KEY=$" .env 2>/dev/null; then
    echo "Generating application key..."
    php artisan key:generate --force
fi

# ============================================
# Step 3: Wait for MySQL to be ready
# ============================================
echo "Waiting for MySQL to be ready..."
MAX_TRIES=30
TRIES=0
until mysql -h "${DB_HOST:-mysql}" -u "${DB_USERNAME:-phpip}" -p"${DB_PASSWORD:-phpip_password}" -e "SELECT 1" >/dev/null 2>&1; do
  TRIES=$((TRIES + 1))
  if [ $TRIES -ge $MAX_TRIES ]; then
    echo "ERROR: MySQL did not become ready in time"
    exit 1
  fi
  echo "MySQL is unavailable - sleeping (attempt $TRIES/$MAX_TRIES)"
  sleep 2
done

echo "MySQL is up - executing command"

# ============================================
# Step 4: Run migrations
# ============================================
echo "Running database migrations..."
php artisan migrate --force || echo "Migrations may have already been run or there was an issue"
echo "Migrations completed"

# ============================================
# Step 5: Seed sample data (if enabled)
# ============================================
# SEED_SAMPLES=true  -> Seed example matters/actors (safe, uses insertOrIgnore)
# SEED_DATABASE=true -> Seed reference data only (countries, roles, event names)
# Both can be set independently

if [ "${SEED_DATABASE:-false}" = "true" ]; then
    echo "Seeding reference data (countries, roles, event names, rules)..."
    php artisan db:seed --class=DatabaseSeeder --force || echo "Reference data seeding completed or skipped"
fi

if [ "${SEED_SAMPLES:-false}" = "true" ]; then
    echo "Seeding sample data (example matters, actors, events)..."
    php artisan db:seed --class=SampleSeeder --force || echo "Sample data seeding completed or skipped"
fi

# ============================================
# Step 6: Optimize application
# ============================================
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
