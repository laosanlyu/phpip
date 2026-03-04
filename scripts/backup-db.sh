#!/bin/bash
# =============================================================================
# phpIP Database Backup Script
# =============================================================================
# Creates a compressed MySQL dump from the Docker container.
#
# Usage:
#   ./scripts/backup-db.sh              # Uses defaults
#   ./scripts/backup-db.sh /custom/dir  # Custom backup directory
#
# Requires: docker compose, gzip
# =============================================================================

set -euo pipefail

# ---- Configuration ----
SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"
PROJECT_DIR="$(dirname "$SCRIPT_DIR")"
COMPOSE_FILE="$PROJECT_DIR/docker-compose.mysql.yml"
BACKUP_DIR="${1:-$PROJECT_DIR/backups}"
KEEP_DAYS=30
TIMESTAMP=$(date +%Y%m%d_%H%M%S)
BACKUP_FILE="phpip_${TIMESTAMP}.sql.gz"
LOG_FILE="$BACKUP_DIR/backup.log"

# ---- Load .env for database credentials ----
if [ -f "$PROJECT_DIR/.env" ]; then
    # Extract only the DB variables we need
    DB_ROOT_PASSWORD=$(grep -E '^DB_ROOT_PASSWORD=' "$PROJECT_DIR/.env" | cut -d'=' -f2-)
    DB_DATABASE=$(grep -E '^DB_DATABASE=' "$PROJECT_DIR/.env" | cut -d'=' -f2- || echo "phpip")
else
    echo "ERROR: .env file not found at $PROJECT_DIR/.env"
    exit 1
fi

if [ -z "$DB_ROOT_PASSWORD" ]; then
    echo "ERROR: DB_ROOT_PASSWORD not set in .env"
    exit 1
fi

# ---- Functions ----
log() {
    local msg="[$(date '+%Y-%m-%d %H:%M:%S')] $1"
    echo "$msg"
    echo "$msg" >> "$LOG_FILE"
}

# ---- Create backup directory ----
mkdir -p "$BACKUP_DIR"

# ---- Check MySQL container is running ----
if ! docker-compose -f "$COMPOSE_FILE" ps mysql --status running --format '{{.Name}}' 2>/dev/null | grep -q .; then
    log "ERROR: MySQL container is not running"
    exit 1
fi

# ---- Perform backup ----
log "Starting backup of database '$DB_DATABASE'..."

docker-compose -f "$COMPOSE_FILE" exec -T mysql \
    mysqldump \
    -u root \
    -p"$DB_ROOT_PASSWORD" \
    --single-transaction \
    --routines \
    --triggers \
    --events \
    "$DB_DATABASE" \
    2>/dev/null \
    | gzip > "$BACKUP_DIR/$BACKUP_FILE"

# ---- Verify backup ----
FILESIZE=$(stat -f%z "$BACKUP_DIR/$BACKUP_FILE" 2>/dev/null || stat -c%s "$BACKUP_DIR/$BACKUP_FILE" 2>/dev/null)

if [ "$FILESIZE" -lt 1000 ]; then
    log "ERROR: Backup file is suspiciously small (${FILESIZE} bytes). Backup may have failed."
    rm -f "$BACKUP_DIR/$BACKUP_FILE"
    exit 1
fi

FILESIZE_MB=$(echo "scale=2; $FILESIZE / 1048576" | bc)
log "Backup completed: $BACKUP_FILE (${FILESIZE_MB} MB)"

# ---- Cleanup old backups ----
DELETED=$(find "$BACKUP_DIR" -name "phpip_*.sql.gz" -mtime +${KEEP_DAYS} -print -delete | wc -l)
if [ "$DELETED" -gt 0 ]; then
    log "Cleaned up $DELETED backup(s) older than $KEEP_DAYS days"
fi

# ---- Summary ----
TOTAL_BACKUPS=$(find "$BACKUP_DIR" -name "phpip_*.sql.gz" | wc -l)
TOTAL_SIZE=$(du -sh "$BACKUP_DIR"/*.sql.gz 2>/dev/null | tail -1 | awk '{print $1}')
log "Total backups: $TOTAL_BACKUPS, Total size: ${TOTAL_SIZE:-0}"
