# Sourdough Dockerfile
# Multi-stage build for Laravel + Next.js in a single container

#==============================================================================
# Stage 1: Build Frontend
#==============================================================================
FROM node:20-alpine AS frontend-builder

WORKDIR /app/frontend

# Copy package files
COPY frontend/package*.json ./

# Install dependencies
# Using npm install instead of npm ci for cross-platform compatibility
# (fsevents and other platform-specific optional deps differ between Windows and Linux)
RUN npm install

# Copy frontend source
COPY frontend/ ./

# Build Next.js app
RUN npm run build

#==============================================================================
# Stage 2: Production Image
#==============================================================================
FROM php:8.3-fpm

LABEL maintainer="Sourdough"
LABEL description="Sourdough - Foundation Application Framework"

# Build arguments for version info
ARG APP_VERSION=0.0.0
ARG BUILD_SHA=development
ARG BUILD_TIME=""

# Environment variables
ENV APP_VERSION=${APP_VERSION}
ENV APP_BUILD_SHA=${BUILD_SHA}
ENV APP_BUILD_TIME=${BUILD_TIME}
ENV APP_ENV=production
ENV APP_DEBUG=false
ENV LOG_CHANNEL=stderr
# Meilisearch defaults (can be overridden at runtime)
ENV MEILI_ENV=production
ENV MEILI_MASTER_KEY=""
# PUID/PGID for volume permission matching (Unraid, Synology, etc.)
# Set these to match your host volume ownership
# Example for Unraid: PUID=99 PGID=100
ENV PUID=""
ENV PGID=""

# Install system dependencies
# Note: npm is needed for development mode (npm run dev)
# Production uses standalone output and doesn't need npm at runtime
RUN apt-get update && apt-get install -y --no-install-recommends \
    nginx \
    supervisor \
    nodejs \
    npm \
    sqlite3 \
    libsqlite3-dev \
    git \
    curl \
    zip \
    unzip \
    libpng-dev \
    libjpeg-dev \
    libfreetype6-dev \
    libzip-dev \
    libonig-dev \
    libicu-dev \
    libpq-dev \
    && docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install -j$(nproc) \
        pdo_mysql \
        pdo_pgsql \
        pdo_sqlite \
        gd \
        zip \
        bcmath \
        intl \
        opcache \
    && rm -rf /var/lib/apt/lists/*

# Install Meilisearch binary (Debian/glibc required; Alpine/musl incompatible)
ARG MEILISEARCH_VERSION=1.34.2
RUN cd /tmp \
    && curl -LO "https://github.com/meilisearch/meilisearch/releases/download/v${MEILISEARCH_VERSION}/meilisearch-linux-amd64" \
    && mv meilisearch-linux-amd64 /usr/local/bin/meilisearch \
    && chmod +x /usr/local/bin/meilisearch

# Install Composer
COPY --from=composer:2.8 /usr/bin/composer /usr/bin/composer

# Set working directory
WORKDIR /var/www/html

# Copy backend files
COPY --chown=www-data:www-data backend/ ./backend/

# Install PHP dependencies
WORKDIR /var/www/html/backend
RUN composer install --no-dev --optimize-autoloader --no-interaction --no-scripts

# Copy built frontend (standalone output - much smaller than full node_modules)
WORKDIR /var/www/html
COPY --from=frontend-builder --chown=www-data:www-data /app/frontend/.next/standalone ./frontend/
COPY --from=frontend-builder --chown=www-data:www-data /app/frontend/.next/static ./frontend/.next/static
COPY --from=frontend-builder --chown=www-data:www-data /app/frontend/public ./frontend/public

# Copy Docker configuration files
COPY docker/nginx.conf /etc/nginx/nginx.conf
COPY docker/supervisord.conf /etc/supervisor/conf.d/supervisord.conf
COPY docker/entrypoint.sh /entrypoint.sh
COPY docker/start-nextjs.sh /start-nextjs.sh
COPY docker/php.ini /usr/local/etc/php/conf.d/app.ini

# Copy VERSION file and CHANGELOG for the in-app changelog page
COPY --chown=www-data:www-data VERSION ./VERSION
COPY --chown=www-data:www-data CHANGELOG.md ./CHANGELOG.md

# Create required directories
RUN mkdir -p /var/www/html/backend/storage/app/public \
    && mkdir -p /var/www/html/backend/storage/app/backups \
    && mkdir -p /var/www/html/backend/storage/framework/cache/data \
    && mkdir -p /var/www/html/backend/storage/framework/sessions \
    && mkdir -p /var/www/html/backend/storage/framework/views \
    && mkdir -p /var/www/html/backend/storage/logs \
    && mkdir -p /var/www/html/backend/database \
    && mkdir -p /var/www/html/data \
    && mkdir -p /var/log/supervisor \
    && mkdir -p /run/nginx \
    && mkdir -p /var/lib/meilisearch \
    && chown -R www-data:www-data /var/lib/meilisearch

# Set permissions and fix line endings for shell scripts
# Note: Most ownership already set via COPY --chown, only need storage permissions
RUN chmod -R 755 /var/www/html/backend/storage \
    && chmod +x /entrypoint.sh \
    && chmod +x /start-nextjs.sh \
    && sed -i 's/\r$//' /entrypoint.sh \
    && sed -i 's/\r$//' /start-nextjs.sh

# Backup migrations to handle volume mount overwrites
RUN cp -r /var/www/html/backend/database/migrations /var/www/migrations-backup

# Expose ports
EXPOSE 80

# Health check
HEALTHCHECK --interval=30s --timeout=10s --start-period=60s --retries=3 \
    CMD curl -f http://localhost/api/health || exit 1

# Entrypoint
ENTRYPOINT ["/entrypoint.sh"]

# Start supervisor
CMD ["/usr/bin/supervisord", "-c", "/etc/supervisor/conf.d/supervisord.conf"]
