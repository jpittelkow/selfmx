#!/bin/sh
set -e

# ASCII Art Banner
cat << 'BANNER'

  .______________________________.
  |\                            /|
  | \........................../ |
  |  '------------------------'  |
  |                              |
  '------------------------------'

            s e l f m x

BANNER
echo "  ${APP_NAME:-selfmx} v${APP_VERSION:-unknown}"
echo "  Build: ${APP_BUILD_SHA:-development}"
echo "  Built on Sourdough v0.8.2 (github.com/Sourdough-start/sourdough)"
echo ""

#==============================================================================
# PUID/PGID Support (for Unraid, Synology, etc.)
#==============================================================================
# If PUID and PGID are set, modify www-data user to match host volume ownership
PUID=${PUID:-}
PGID=${PGID:-}

if [ -n "${PUID}" ] && [ -n "${PGID}" ]; then
    echo "Configuring user permissions: PUID=${PUID} PGID=${PGID}"
    
    # Modify www-data group's GID if different
    CURRENT_GID=$(id -g www-data)
    if [ "${CURRENT_GID}" != "${PGID}" ]; then
        echo "  Changing www-data GID from ${CURRENT_GID} to ${PGID}"
        groupmod -o -g "${PGID}" www-data
    fi
    
    # Modify www-data user's UID if different
    CURRENT_UID=$(id -u www-data)
    if [ "${CURRENT_UID}" != "${PUID}" ]; then
        echo "  Changing www-data UID from ${CURRENT_UID} to ${PUID}"
        usermod -o -u "${PUID}" www-data
    fi
    
    echo "  User www-data: UID=$(id -u www-data) GID=$(id -g www-data)"
else
    echo "No PUID/PGID set, using default www-data (UID=$(id -u www-data) GID=$(id -g www-data))"
fi

# Directory paths
BACKEND_DIR="/var/www/html/backend"
FRONTEND_DIR="/var/www/html/frontend"
DATA_DIR="/var/www/html/data"
DB_PATH="${DATA_DIR}/database.sqlite"

# Restore migrations if they were overwritten by volume mount (skip existing files)
if [ -d "/var/www/migrations-backup" ]; then
    echo "Syncing migrations from backup..."
    cp -rn /var/www/migrations-backup/* ${BACKEND_DIR}/database/migrations/ 2>/dev/null || true
fi

# Create .next directory for development mode (volume mount overwrites built .next)
echo "Setting up frontend build directories..."
if [ "${APP_ENV}" = "local" ] || [ "${APP_ENV}" = "development" ]; then
    # In dev, clear .next so www-data can create/delete files (avoids EACCES from root-owned leftovers)
    echo "Development mode: Clearing .next for fresh dev build..."
    if [ -d "${FRONTEND_DIR}/.next" ]; then
        # Show current ownership for debugging
        echo "Current .next ownership:"
        ls -la ${FRONTEND_DIR}/.next 2>/dev/null | head -5 || echo "  (empty or inaccessible)"
        # Remove contents (may fail if volume is empty, that's OK)
        find ${FRONTEND_DIR}/.next -mindepth 1 -maxdepth 1 -exec rm -rf {} \; 2>/dev/null || true
    fi
fi
mkdir -p ${FRONTEND_DIR}/.next/cache
echo "Setting .next ownership to www-data..."
if ! chown -R www-data:www-data ${FRONTEND_DIR}/.next; then
    echo "WARNING: Failed to set .next ownership - Next.js may have permission issues"
fi
if ! chmod -R 775 ${FRONTEND_DIR}/.next; then
    echo "WARNING: Failed to set .next permissions"
fi

# In development mode, remove BUILD_ID to force Next.js dev mode with hot reload
if [ "${APP_ENV}" = "local" ] || [ "${APP_ENV}" = "development" ]; then
    echo "Development mode: Clearing Next.js production build marker..."
    rm -f ${FRONTEND_DIR}/.next/BUILD_ID
fi

# Ensure data directory exists with proper permissions
echo "Setting up data directory..."
mkdir -p ${DATA_DIR}
chown -R www-data:www-data ${DATA_DIR}
chmod 775 ${DATA_DIR}

# Setup Meilisearch data directory (data for DB, dumps and snapshots for production operations)
echo "Setting up Meilisearch data directory..."
mkdir -p /var/lib/meilisearch/data /var/lib/meilisearch/dumps /var/lib/meilisearch/snapshots
chown -R www-data:www-data /var/lib/meilisearch
chmod -R 755 /var/lib/meilisearch

# Ensure storage directories exist with proper permissions
echo "Setting up storage directories..."
mkdir -p ${BACKEND_DIR}/storage/app/public
mkdir -p ${BACKEND_DIR}/storage/app/backups
mkdir -p ${BACKEND_DIR}/storage/framework/cache/data
mkdir -p ${BACKEND_DIR}/storage/framework/sessions
mkdir -p ${BACKEND_DIR}/storage/framework/views
mkdir -p ${BACKEND_DIR}/storage/logs
chown -R www-data:www-data ${BACKEND_DIR}/storage
chmod -R 775 ${BACKEND_DIR}/storage

# Create nginx client body temp directory with proper permissions
echo "Setting up nginx temp directories..."
mkdir -p /tmp/nginx_client_body
chown -R www-data:www-data /tmp/nginx_client_body
chmod -R 755 /tmp/nginx_client_body

# Create SQLite database if it doesn't exist
if [ ! -f "${DB_PATH}" ]; then
    echo "Creating SQLite database..."
    touch ${DB_PATH}
fi
# Always ensure database has correct permissions (volume mounts may have wrong ownership)
chown www-data:www-data ${DB_PATH}
chmod 664 ${DB_PATH}

# Ensure .env exists (needed before any artisan commands)
cd ${BACKEND_DIR}
if [ ! -f ".env" ] && [ -f ".env.example" ]; then
    echo "Creating .env from .env.example..."
    cp .env.example .env
fi

# Update .env database path
if [ -f ".env" ]; then
    sed -i "s|DB_DATABASE=.*|DB_DATABASE=${DB_PATH}|g" .env
fi

#==============================================================================
# Auto-generate and persist APP_KEY to data volume
#==============================================================================
KEY_FILE="${DATA_DIR}/.app_key"
if [ -z "${APP_KEY}" ]; then
    if [ -f "${KEY_FILE}" ]; then
        echo "Loading APP_KEY from persistent storage..."
        export APP_KEY=$(cat "${KEY_FILE}")
    else
        echo "Generating new APP_KEY..."
        # Ensure .env exists for artisan to work
        cp .env.example .env 2>/dev/null || true
        APP_KEY=$(php artisan key:generate --show)
        echo "${APP_KEY}" > "${KEY_FILE}"
        chmod 600 "${KEY_FILE}"
        chown www-data:www-data "${KEY_FILE}"
        export APP_KEY
        echo "APP_KEY generated and saved to persistent storage"
    fi
fi
# Write APP_KEY into .env for Laravel
if [ -f ".env" ]; then
    sed -i "s|^APP_KEY=.*|APP_KEY=${APP_KEY}|" .env
elif [ -f ".env.example" ]; then
    cp .env.example .env
    sed -i "s|^APP_KEY=.*|APP_KEY=${APP_KEY}|" .env
fi

#==============================================================================
# Auto-generate and persist MEILI_MASTER_KEY to data volume
#==============================================================================
MEILI_KEY_FILE="${DATA_DIR}/.meili_key"
if [ -z "${MEILI_MASTER_KEY}" ]; then
    if [ -f "${MEILI_KEY_FILE}" ]; then
        echo "Loading MEILI_MASTER_KEY from persistent storage..."
        export MEILI_MASTER_KEY=$(cat "${MEILI_KEY_FILE}")
    else
        echo "Generating new MEILI_MASTER_KEY..."
        MEILI_MASTER_KEY=$(openssl rand -base64 32)
        echo "${MEILI_MASTER_KEY}" > "${MEILI_KEY_FILE}"
        chmod 600 "${MEILI_KEY_FILE}"
        chown www-data:www-data "${MEILI_KEY_FILE}"
        export MEILI_MASTER_KEY
        echo "MEILI_MASTER_KEY generated and saved to persistent storage"
    fi
fi
export MEILISEARCH_KEY="${MEILISEARCH_KEY:-${MEILI_MASTER_KEY}}"

#==============================================================================
# Auto-generate and persist REVERB_APP_SECRET to data volume
#==============================================================================
REVERB_SECRET_FILE="${DATA_DIR}/.reverb_app_secret"
if [ -z "${REVERB_APP_SECRET}" ] || [ "${REVERB_APP_SECRET}" = "selfmx-secret" ]; then
    if [ -f "${REVERB_SECRET_FILE}" ]; then
        echo "Loading REVERB_APP_SECRET from persistent storage..."
        export REVERB_APP_SECRET=$(cat "${REVERB_SECRET_FILE}")
    else
        echo "Generating new REVERB_APP_SECRET..."
        REVERB_APP_SECRET=$(openssl rand -base64 32)
        echo "${REVERB_APP_SECRET}" > "${REVERB_SECRET_FILE}"
        chmod 600 "${REVERB_SECRET_FILE}"
        chown www-data:www-data "${REVERB_SECRET_FILE}"
        export REVERB_APP_SECRET
        echo "REVERB_APP_SECRET generated and saved to persistent storage"
    fi
fi

#==============================================================================
# Auto-derive environment variables from APP_URL
#==============================================================================
# Default FRONTEND_URL to APP_URL (same origin in single-container setup)
export FRONTEND_URL="${FRONTEND_URL:-${APP_URL:-http://localhost}}"

# Auto-derive SANCTUM_STATEFUL_DOMAINS from APP_URL if not set
if [ -z "${SANCTUM_STATEFUL_DOMAINS}" ] && [ -n "${APP_URL}" ]; then
    DOMAIN=$(echo "${APP_URL}" | sed 's|^http[s]*://||' | sed 's|/.*||')
    export SANCTUM_STATEFUL_DOMAINS="localhost,${DOMAIN}"
    echo "Auto-derived SANCTUM_STATEFUL_DOMAINS=${SANCTUM_STATEFUL_DOMAINS}"
fi

# Internal API URL for server-side fetches (e.g. dynamic manifest route).
# In the single-container setup Nginx listens on port 80 and proxies to Laravel.
export INTERNAL_API_URL="${INTERNAL_API_URL:-http://127.0.0.1:80}"

# Remove stale config/route cache files directly so env() works during migrations.
# Must use rm (not artisan config:clear) because artisan itself fails if a stale
# cache exists with baked-in env values from a previous boot.
rm -f ${BACKEND_DIR}/bootstrap/cache/config.php
rm -f ${BACKEND_DIR}/bootstrap/cache/routes-v7.php

# Run database migrations with Scout disabled (Meilisearch not started yet)
echo "Running database migrations..."
SCOUT_DRIVER=null php artisan migrate --force

# Clear and cache config in production
# NOTE: This must run AFTER all env vars are derived (FRONTEND_URL, SANCTUM_STATEFUL_DOMAINS, etc.)
# because config:cache bakes env values into the cached config file.
if [ "${APP_ENV}" = "production" ]; then
    echo "Optimizing for production..."
    php artisan config:cache
    php artisan route:cache
    php artisan view:cache
fi

# Create storage link (ignore if already exists)
php artisan storage:link >/dev/null 2>&1 || true

# Fix storage ownership after artisan commands (which run as root and may create
# root-owned files/directories in storage, e.g. cache, views, sessions)
echo "Fixing storage permissions after setup..."
chown -R www-data:www-data ${BACKEND_DIR}/storage
chmod -R 775 ${BACKEND_DIR}/storage
chown -R www-data:www-data ${BACKEND_DIR}/bootstrap/cache
chmod -R 775 ${BACKEND_DIR}/bootstrap/cache

echo "  === ${APP_NAME:-selfmx} is Ready! ==="
echo ""

# Execute the main command
exec "$@"
