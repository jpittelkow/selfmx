# ADR-009: Docker Single-Container Architecture

## Status

Accepted

## Date

2026-01-24

## Context

selfmx targets self-hosted deployments where simplicity is paramount. Users should be able to:
- Deploy with a single `docker run` command
- Avoid complex multi-container orchestration
- Have all services running with minimal configuration
- Update easily with image pulls

We need to balance simplicity with maintainability and production readiness.

## Decision

We will package all services in a **single Docker container** using Supervisor for process management.

### Container Architecture

```
┌─────────────────────────────────────────────────────────────┐
│                    Single Docker Container                   │
│                     (selfmx:latest)                      │
├─────────────────────────────────────────────────────────────┤
│                                                              │
│  ┌────────────────────────────────────────────────────────┐ │
│  │                     Supervisor                          │ │
│  │            (Process Manager - PID 1)                    │ │
│  └─────────────────────┬──────────────────────────────────┘ │
│       ┌────────┬───────┼────────┬────────┐                  │
│       ▼        ▼       ▼        ▼        ▼                  │
│  ┌────────┐ ┌────────┐ ┌────────┐ ┌────────┐ ┌────────────┐ │
│  │ Nginx  │ │PHP-FPM │ │ Node   │ │ Queue  │ │ Meilisearch│ │
│  │ :80    │ │ :9000  │ │ :3000  │ │Worker  │ │ :7700      │ │
│  └────────┘ └────────┘ └────────┘ └────────┘ └────────────┘ │
│       │        │         │         │                          │
│       └────────┴─────────┴─────────┘                          │
│         ▼                                                   │
│  ┌─────────────────────────────────────────────────────────┐│
│  │                      Volumes                             ││
│  │  /data  /data/backups  /var/lib/meilisearch (search)     ││
│  └─────────────────────────────────────────────────────────┘│
│                                                              │
└─────────────────────────────────────────────────────────────┘
```

### Process Management

Supervisor manages five main process types (plus scheduler in the actual config):

1. **Nginx** - Reverse proxy, static files, SSL termination
2. **PHP-FPM** - Laravel API execution
3. **Node** - Next.js frontend (production build)
4. **Queue Worker** - Background job processing
5. **Meilisearch** - Search engine (listens on 127.0.0.1:7700, data in `/var/lib/meilisearch`)

```ini
# supervisord.conf (excerpt)
[supervisord]
nodaemon=true
user=root

[program:meilisearch]
command=/usr/local/bin/meilisearch --db-path /var/lib/meilisearch/data --http-addr 127.0.0.1:7700
autorestart=true
priority=1
user=www-data
stdout_logfile=/dev/stdout
stderr_logfile=/dev/stderr

[program:nginx]
command=nginx -g "daemon off;"
autorestart=true
stdout_logfile=/dev/stdout
stderr_logfile=/dev/stderr

[program:php-fpm]
command=php-fpm --nodaemonize
autorestart=true
stdout_logfile=/dev/stdout
stderr_logfile=/dev/stderr

[program:node]
command=node /app/frontend/.next/standalone/server.js
autorestart=true
environment=PORT="3000",HOSTNAME="0.0.0.0"
stdout_logfile=/dev/stdout
stderr_logfile=/dev/stderr

[program:queue]
command=php /app/backend/artisan queue:work --sleep=3 --tries=3
autorestart=true
stdout_logfile=/dev/stdout
stderr_logfile=/dev/stderr
```

### Nginx Configuration

```nginx
server {
    listen 80;
    server_name _;
    
    # Frontend (Next.js)
    location / {
        proxy_pass http://127.0.0.1:3000;
        proxy_http_version 1.1;
        proxy_set_header Upgrade $http_upgrade;
        proxy_set_header Connection 'upgrade';
        proxy_set_header Host $host;
        proxy_cache_bypass $http_upgrade;
    }
    
    # Backend API
    location /api {
        try_files $uri $uri/ /index.php?$query_string;
    }
    
    # PHP handling
    location ~ \.php$ {
        fastcgi_pass 127.0.0.1:9000;
        fastcgi_param SCRIPT_FILENAME /app/backend/public$fastcgi_script_name;
        include fastcgi_params;
    }
}
```

### Multi-Stage Build

The production image uses Debian (not Alpine) because embedded Meilisearch requires glibc; Alpine's musl is incompatible with Meilisearch binaries.

```dockerfile
# Stage 1: Build frontend
FROM node:20-slim AS frontend-builder
WORKDIR /build
COPY frontend/package*.json ./
RUN npm ci
COPY frontend/ ./
RUN npm run build

# Stage 2: Build backend dependencies
FROM composer:2 AS backend-builder
WORKDIR /build
COPY backend/composer.* ./
RUN composer install --no-dev --optimize-autoloader

# Stage 3: Production image (Debian for Meilisearch/glibc)
FROM php:8.3-fpm

# Install system dependencies
RUN apt-get update && apt-get install -y --no-install-recommends nginx supervisor nodejs npm sqlite3 curl \
    && rm -rf /var/lib/apt/lists/*

# Copy application
COPY --from=backend-builder /build/vendor /app/backend/vendor
COPY backend/ /app/backend/
COPY --from=frontend-builder /build/.next/standalone /app/frontend/.next/standalone
COPY --from=frontend-builder /build/.next/static /app/frontend/.next/static
COPY --from=frontend-builder /build/public /app/frontend/public

# Copy configs
COPY docker/nginx.conf /etc/nginx/nginx.conf
COPY docker/supervisord.conf /etc/supervisor/supervisord.conf
COPY docker/php.ini /usr/local/etc/php/php.ini
COPY docker/entrypoint.sh /entrypoint.sh

# Create data directory
RUN mkdir -p /data && chown -R www-data:www-data /data

EXPOSE 80
VOLUME ["/data"]

ENTRYPOINT ["/entrypoint.sh"]
CMD ["supervisord", "-c", "/etc/supervisor/supervisord.conf"]
```

### Entrypoint Script

```bash
#!/bin/sh
set -e

# Create database if not exists
if [ ! -f /data/database.sqlite ]; then
    touch /data/database.sqlite
    chown www-data:www-data /data/database.sqlite
fi

# Link storage
ln -sf /data/storage /app/backend/storage/app

# Run migrations
cd /app/backend
php artisan migrate --force

# Generate key if not set
if [ -z "$APP_KEY" ]; then
    php artisan key:generate --force
fi

# Cache config for production
if [ "$APP_ENV" = "production" ]; then
    php artisan config:cache
    php artisan route:cache
    php artisan view:cache
fi

exec "$@"
```

### Volume Strategy

| Volume | Purpose | Default Location |
|--------|---------|------------------|
| `/data` | All persistent data | Named volume |
| `/data/database.sqlite` | SQLite database | Inside /data |
| `/data/storage` | Uploaded files | Inside /data |
| `/data/backups` | Backup files | Inside /data |
| `/var/lib/meilisearch` | Meilisearch index data | Named volume `meilisearch_data` |

### docker-compose Files

Development:
```yaml
# docker-compose.yml
version: '3.8'
services:
  app:
    build: .
    ports:
      - "8080:80"
    volumes:
      - appdata:/data
      - ./backend:/app/backend  # Hot reload
      - ./frontend:/app/frontend
    environment:
      - APP_ENV=local
      - APP_DEBUG=true

volumes:
  appdata:
```

Production:
```yaml
# docker-compose.prod.yml
version: '3.8'
services:
  app:
    image: ghcr.io/selfmx/selfmx:latest
    ports:
      - "80:80"
    volumes:
      - appdata:/data
    environment:
      - APP_ENV=production
      - APP_DEBUG=false
      - APP_KEY=${APP_KEY}
    restart: unless-stopped

volumes:
  appdata:
```

## Consequences

### Positive

- Single command deployment (`docker run`)
- No container orchestration required
- All logs in one place
- Simple backup (single volume)
- Easy version updates

### Negative

- Cannot scale individual components
- All processes share resources
- Larger image size than split containers
- Supervisor adds slight complexity

### Neutral

- Suitable for small to medium deployments
- Large deployments can split containers if needed
- Health checks must monitor all processes

## Related Decisions

- [ADR-001: Technology Stack](./001-technology-stack.md)
- [ADR-010: Database Abstraction Strategy](./010-database-abstraction.md)

## Notes

### Health Check

```dockerfile
HEALTHCHECK --interval=30s --timeout=10s --start-period=60s --retries=3 \
  CMD curl -f http://localhost/api/health || exit 1
```

The health endpoint checks:
- PHP-FPM responding
- Database connection
- Queue worker running
- Disk space available

### Production Safety

From DanaVision, we include production safety measures:
- Prevent accidental database deletion
- Require confirmation for destructive operations
- Backup before major operations
