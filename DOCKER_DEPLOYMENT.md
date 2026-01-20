# Docker Deployment Guide for phpIP

Deploy phpIP using Docker and Docker Compose.

## Prerequisites

- Docker (version 20.10+)
- Docker Compose (version 2.0+)

## Quick Start - Production

### 1. Copy Environment Template

```bash
cp .env.prod.example .env
```

### 2. Configure Environment

Edit `.env` and set these **required** values:

```bash
# Database - must match docker-compose.prod.yml defaults or set your own
DB_HOST=mysql
DB_DATABASE=phpip
DB_USERNAME=phpip
DB_PASSWORD=phpip_password      # Change for production!
DB_ROOT_PASSWORD=root_password  # Change for production!

# Application
APP_URL=http://your-server-ip-or-domain
COMPANY_NAME="Your Company"

# Email (required for notifications)
MAIL_HOST=smtp.gmail.com
MAIL_USERNAME=your-email@gmail.com
MAIL_PASSWORD=your-app-password
MAIL_FROM_ADDRESS=noreply@your-domain.com
MAIL_TO=admin@your-domain.com
```

### 3. Build and Start

```bash
docker compose -f docker-compose.prod.yml up -d --build
```

The entrypoint script will automatically:
- Install PHP dependencies if missing
- Generate APP_KEY if not set
- Wait for MySQL to be ready
- Run database migrations

### 4. First-Time Database Setup (if using schema dump)

If you're deploying with an existing database schema:

```bash
# Load the schema (uses the phpip user, no root needed)
docker compose -f docker-compose.prod.yml exec app sh -c \
  "mysql -h mysql -u \$DB_USERNAME -p\$DB_PASSWORD \$DB_DATABASE < database/schema/mysql-schema.sql"
```

### 5. Access Application

The application is accessible via:
- **IP address**: `http://YOUR_SERVER_IP`
- **Domain**: `http://your-domain.com` (requires DNS configuration)

The nginx server accepts any hostname (`server_name _;`), so no configuration change is needed when moving to a different server.

## Troubleshooting

### Common Issues

| Issue | Cause | Solution |
|-------|-------|----------|
| 502 Bad Gateway | PHP container not ready | Wait 30 seconds, check `docker logs phpip-app` |
| "MySQL is unavailable" loop | Missing .env or wrong DB credentials | Verify `.env` exists with correct `DB_*` values |
| DEFINER privilege error | Schema has hardcoded DEFINER | Schema is fixed to use CURRENT_USER |
| Missing vendor folder | Volume mount overwrites container | Entrypoint auto-runs `composer install` |

### Check Container Status

```bash
docker compose -f docker-compose.prod.yml ps
docker compose -f docker-compose.prod.yml logs app
docker compose -f docker-compose.prod.yml logs mysql
```

## Available Files

### Environment Files

| File | Purpose | Use With |
|------|---------|----------|
| `.env.prod.example` | Production: `APP_DEBUG=false`, Redis caching | `docker-compose.prod.yml` |
| `.env.dev.example` | Development: `APP_DEBUG=true`, file caching, port 8080 | `docker-compose.dev.yml` |

### Docker Compose Files

| File | Database | Use Case |
|------|----------|----------|
| `docker-compose.prod.yml` | MySQL 8.0 | **Production** (recommended) |
| `docker-compose.dev.yml` | MySQL 8.0 | Development with Xdebug + Vite HMR |
| `docker-compose.mariadb.yml` | MariaDB 11.2 | Future migration (experimental) |

## Container Architecture

### Production (`docker-compose.prod.yml`)

| Container | Purpose | Port |
|-----------|---------|------|
| nginx | Web server | 80, 443 |
| app | PHP-FPM application | 9000 (internal) |
| mysql | Database | 3306 |
| redis | Cache & sessions | 6379 |
| scheduler | Laravel cron jobs | - |

### Development (`docker-compose.dev.yml`)

| Container | Purpose | Port |
|-----------|---------|------|
| nginx | Web server | 8080, 8443 |
| app | PHP-FPM with Xdebug | 9000 (internal) |
| mysql | Database | 3307 |
| redis | Cache | 6380 |
| vite | Hot module replacement | 5173 |

## Development Environment

For local development with hot reloading:

```bash
# Copy dev environment
cp .env.dev.example .env

# Build and start
docker compose -f docker-compose.dev.yml build
docker compose -f docker-compose.dev.yml up -d

# Initialize (first time)
docker compose -f docker-compose.dev.yml exec app php artisan key:generate
docker compose -f docker-compose.dev.yml exec -T mysql mysql -u root -pdev_root_123 phpip_dev < database/schema/mysql-schema.sql
docker compose -f docker-compose.dev.yml exec app php artisan migrate --force
```

Access at http://localhost:8080 with Vite HMR at http://localhost:5173.

## Database Seeding

After loading the schema, you can populate the database with reference data and sample records.

### Seed Reference Data (Required)

This seeds essential reference data (countries, event names, matter categories, roles, etc.):

**Production:**
```bash
docker compose -f docker-compose.prod.yml exec app php artisan db:seed
```

**Development:**
```bash
docker compose -f docker-compose.dev.yml exec app php artisan db:seed
```

### Seed Sample/Example Data (Optional)

Add example records (sample actors, matters, tasks, events) for testing or demo purposes:

**Production:**
```bash
docker compose -f docker-compose.prod.yml exec app php artisan db:seed --class=SampleSeeder
```

**Development:**
```bash
docker compose -f docker-compose.dev.yml exec app php artisan db:seed --class=SampleSeeder
```

### Individual Sample Seeders

You can also seed specific sample data:

```bash
# Seed sample actors (clients, agents, etc.)
docker compose -f docker-compose.dev.yml exec app php artisan db:seed --class=ActorSampleSeeder

# Seed sample matters (patent/trademark cases)
docker compose -f docker-compose.dev.yml exec app php artisan db:seed --class=MatterSampleSeeder

# Seed sample tasks (deadlines, reminders)
docker compose -f docker-compose.dev.yml exec app php artisan db:seed --class=TaskSampleSeeder

# Seed sample events (filing dates, grant dates)
docker compose -f docker-compose.dev.yml exec app php artisan db:seed --class=EventSampleSeeder

# Seed sample classifiers (keywords, IPC codes)
docker compose -f docker-compose.dev.yml exec app php artisan db:seed --class=ClassifierSampleSeeder
```

### Fresh Start with All Data

To reset the database and seed everything from scratch:

```bash
# Development - reset, migrate, and seed all data
docker compose -f docker-compose.dev.yml exec app php artisan migrate:fresh --seed
docker compose -f docker-compose.dev.yml exec app php artisan db:seed --class=SampleSeeder
```

> **Note:** `migrate:fresh` drops all tables. Use with caution in production!

## Optional Configurations

Not required for basic deployment:

### EPO Patent Services (OPS)
For automatic patent family import. Register at https://developers.epo.org/
```bash
OPS_APP_KEY=your_key
OPS_SECRET=your_secret
```

### Renewr API
For renewal management:
```bash
RENEWR_API_URL=https://api.renewr.io/...
RENEWR_API_KEY=your_key
```

### SharePoint Integration
For document management:
```bash
SHAREPOINT_ENABLED=true
SHAREPOINT_CLIENT_ID=your_id
SHAREPOINT_CLIENT_SECRET=your_secret
```

## Common Commands

Replace `docker-compose.prod.yml` with `docker-compose.dev.yml` for development.

```bash
# View logs
docker compose -f docker-compose.prod.yml logs -f app

# Run artisan commands
docker compose -f docker-compose.prod.yml exec app php artisan migrate
docker compose -f docker-compose.prod.yml exec app php artisan config:cache

# Access database
docker compose -f docker-compose.prod.yml exec mysql mysql -u phpip -p phpip

# Rebuild
docker compose -f docker-compose.prod.yml down
docker compose -f docker-compose.prod.yml build
docker compose -f docker-compose.prod.yml up -d

# Stop and remove data
docker compose -f docker-compose.prod.yml down -v
```

## Backup and Restore

```bash
# Backup
docker compose -f docker-compose.prod.yml exec mysql mysqldump -u phpip -p phpip > backup.sql

# Restore
docker compose -f docker-compose.prod.yml exec -T mysql mysql -u phpip -p phpip < backup.sql
```

## Production Checklist

- [ ] `APP_ENV=production` and `APP_DEBUG=false`
- [ ] Strong database passwords (changed from defaults)
- [ ] Proper `APP_URL` with your domain
- [ ] SMTP email configured
- [ ] SSL/HTTPS enabled
- [ ] Database backups scheduled
- [ ] Firewall configured

## Troubleshooting

**Containers won't start:**
```bash
docker compose -f docker-compose.prod.yml logs
```

**Permission errors:**
```bash
docker compose -f docker-compose.prod.yml exec app chown -R www-data:www-data /var/www/html/storage /var/www/html/bootstrap/cache
```

**Clear caches:**
```bash
docker compose -f docker-compose.prod.yml exec app php artisan config:clear
docker compose -f docker-compose.prod.yml exec app php artisan cache:clear
```

**Database connection errors:**
- Ensure `DB_HOST=mysql` in `.env`
- Wait 30-60 seconds for database initialization

**Schema loading fails with SSL error:**
Load the schema directly from the MySQL container:
```bash
docker compose -f docker-compose.prod.yml exec -T mysql mysql -u root -pYOUR_ROOT_PASSWORD phpip < database/schema/mysql-schema.sql
```

## MariaDB (Experimental)

The `docker-compose.mariadb.yml` is provided for future migration but is not recommended for production use. The bundled database schema was created for MySQL 8.0 and has compatibility issues with MariaDB:

- Different collation support (`utf8mb4_0900_ai_ci` vs `utf8mb4_unicode_ci`)
- Generated column syntax differences
- Foreign key constraint handling

For production deployments, use `docker-compose.prod.yml` with MySQL 8.0.

## Support

- [phpIP Wiki](https://github.com/jjdejong/phpip/wiki)
- [Report Issues](https://github.com/jjdejong/phpip/issues)
