#!/bin/bash

# Docker Deployment Test Script for phpIP
# This script tests the Docker deployment to ensure everything works correctly

set -e  # Exit on any error

echo "================================================"
echo "phpIP Docker Deployment Test Script"
echo "================================================"
echo ""

# Colors for output
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
NC='\033[0m' # No Color

# Function to print colored output
print_success() {
    echo -e "${GREEN}✓ $1${NC}"
}

print_error() {
    echo -e "${RED}✗ $1${NC}"
}

print_warning() {
    echo -e "${YELLOW}⚠ $1${NC}"
}

print_info() {
    echo -e "${YELLOW}ℹ $1${NC}"
}

# Check if Docker is installed
echo "1. Checking prerequisites..."
if ! command -v docker &> /dev/null; then
    print_error "Docker is not installed. Please install Docker first."
    exit 1
fi
print_success "Docker is installed"

if ! command -v docker-compose &> /dev/null && ! docker compose version &> /dev/null; then
    print_error "Docker Compose is not installed. Please install Docker Compose first."
    exit 1
fi
print_success "Docker Compose is installed"

# Detect which compose command to use
if docker compose version &> /dev/null 2>&1; then
    COMPOSE_CMD="docker compose"
else
    COMPOSE_CMD="docker-compose"
fi
print_info "Using: $COMPOSE_CMD"

echo ""
echo "2. Checking environment configuration..."

# Check if .env exists
if [ ! -f .env ]; then
    print_warning ".env file not found. Creating from .env.production..."
    cp .env.production .env
    print_info "Please edit .env file with your settings before continuing"
    print_info "At minimum, change DB_PASSWORD and DB_ROOT_PASSWORD"
    read -p "Press Enter to continue after editing .env, or Ctrl+C to exit..."
fi
print_success ".env file exists"

# Check if required directories exist
if [ ! -d "storage" ]; then
    print_error "storage directory not found. Are you in the phpIP root directory?"
    exit 1
fi
print_success "Project structure looks correct"

echo ""
echo "3. Building Docker images..."
$COMPOSE_CMD build
print_success "Docker images built successfully"

echo ""
echo "4. Starting containers..."
$COMPOSE_CMD up -d

# Wait for containers to start
echo "Waiting for containers to be healthy..."
sleep 10

echo ""
echo "5. Checking container status..."
RUNNING_CONTAINERS=$($COMPOSE_CMD ps --services --filter "status=running" | wc -l)
EXPECTED_CONTAINERS=5  # app, nginx, mysql, redis, scheduler

if [ "$RUNNING_CONTAINERS" -ge 4 ]; then
    print_success "All essential containers are running ($RUNNING_CONTAINERS containers)"
else
    print_error "Not all containers are running. Expected at least 4, got $RUNNING_CONTAINERS"
    $COMPOSE_CMD ps
    exit 1
fi

echo ""
echo "6. Checking database connectivity..."
MAX_RETRIES=30
RETRY=0

# Detect which database service is running (mysql or mariadb)
DB_SERVICE="mysql"
if $COMPOSE_CMD ps mariadb 2>/dev/null | grep -q "running"; then
    DB_SERVICE="mariadb"
fi
print_info "Database service detected: $DB_SERVICE"

while [ $RETRY -lt $MAX_RETRIES ]; do
    if $COMPOSE_CMD exec -T $DB_SERVICE mysqladmin ping -h localhost --silent 2>/dev/null; then
        print_success "$DB_SERVICE is responding"
        break
    fi
    RETRY=$((RETRY+1))
    if [ $RETRY -eq $MAX_RETRIES ]; then
        print_error "$DB_SERVICE is not responding after $MAX_RETRIES attempts"
        exit 1
    fi
    echo "Waiting for $DB_SERVICE to be ready... ($RETRY/$MAX_RETRIES)"
    sleep 2
done

echo ""
echo "7. Generating application key..."
if $COMPOSE_CMD exec -T app php artisan key:generate --force; then
    print_success "Application key generated"
else
    print_error "Failed to generate application key"
    exit 1
fi

echo ""
echo "8. Running database migrations..."
if $COMPOSE_CMD exec -T app php artisan migrate --force; then
    print_success "Database migrations completed"
else
    print_warning "Database migrations may have failed or already run"
fi

echo ""
echo "9. Checking Redis connectivity..."
if $COMPOSE_CMD exec -T redis redis-cli ping 2>/dev/null | grep -q "PONG"; then
    print_success "Redis is responding"
else
    print_warning "Redis is not responding (this is optional)"
fi

echo ""
echo "10. Testing web server..."
APP_URL=$(grep APP_URL .env | cut -d '=' -f2 | tr -d '"' | tr -d "'")
NGINX_PORT=$(grep NGINX_PORT .env | cut -d '=' -f2 | tr -d '"' | tr -d "'")
NGINX_PORT=${NGINX_PORT:-80}

sleep 3  # Give nginx a moment to fully start

if curl -f -s -o /dev/null "http://localhost:$NGINX_PORT"; then
    print_success "Web server is responding on port $NGINX_PORT"
else
    print_warning "Web server may not be responding yet (this can take a moment)"
fi

echo ""
echo "11. Checking PHP-FPM..."
if $COMPOSE_CMD exec -T app php -v &>/dev/null; then
    PHP_VERSION=$($COMPOSE_CMD exec -T app php -v | head -n 1)
    print_success "PHP is working: $PHP_VERSION"
else
    print_error "PHP is not working"
    exit 1
fi

echo ""
echo "12. Verifying Laravel installation..."
if $COMPOSE_CMD exec -T app php artisan --version &>/dev/null; then
    LARAVEL_VERSION=$($COMPOSE_CMD exec -T app php artisan --version)
    print_success "Laravel is working: $LARAVEL_VERSION"
else
    print_error "Laravel is not working"
    exit 1
fi

echo ""
echo "13. Checking file permissions..."
if $COMPOSE_CMD exec -T app test -w /var/www/html/storage; then
    print_success "Storage directory is writable"
else
    print_warning "Storage directory may not be writable"
fi

echo ""
echo "14. Running a test database query..."
if $COMPOSE_CMD exec -T app php artisan db:show &>/dev/null; then
    print_success "Database connection is working"
else
    print_error "Database connection failed"
    exit 1
fi

echo ""
echo "================================================"
echo "           Deployment Test Summary"
echo "================================================"
echo ""
print_success "All critical tests passed!"
echo ""
echo "Your phpIP installation is ready:"
echo "  • URL: http://localhost:$NGINX_PORT"
echo "  • Database: $DB_SERVICE (container: $DB_SERVICE)"
echo "  • Cache: Redis (container: redis)"
echo ""
echo "Useful commands:"
echo "  • View logs:    $COMPOSE_CMD logs -f app"
echo "  • Stop:         $COMPOSE_CMD down"
echo "  • Restart:      $COMPOSE_CMD restart"
echo "  • Shell access: $COMPOSE_CMD exec app sh"
echo ""
print_info "Access the application at: http://localhost:$NGINX_PORT"
echo ""

# Optional: Show container status
echo "Container Status:"
$COMPOSE_CMD ps

echo ""
print_warning "Remember to:"
echo "  1. Change default database passwords in .env"
echo "  2. Configure your mail settings in .env"
echo "  3. Set up SSL certificates for production"
echo "  4. Configure proper backups"
echo ""
