# Docker Next.js Volume Mount Fix - 2026-01-26

## Overview
Fixed a 500 error when accessing the frontend in development mode caused by the Next.js `.next` directory not being writable within the Docker container.

## Problem
When running in development mode with docker-compose, users encountered a 500 error with these webpack cache errors:
```
Error: ENOENT: no such file or directory, mkdir '/var/www/html/frontend/.next/cache'
Error: ENOENT: no such file or directory, lstat '/var/www/html/frontend/.next'
```

## Root Cause
1. The Dockerfile builds the frontend and creates the `.next` directory during the build stage
2. The docker-compose.yml mounts `./frontend` from the host, which **overwrites** the built `.next` directory
3. The Next.js process runs as `www-data` user and cannot create the `.next` directory on the mounted volume due to permission issues

## Solution
Three changes were made:

### 1. Added named volume for `.next` directory (docker-compose.yml)
Similar to how `node_modules` is handled, added `frontend_next_cache` named volume to isolate the Next.js build cache:
```yaml
volumes:
  - frontend_next_cache:/var/www/html/frontend/.next
```

### 2. Create `.next` directory in entrypoint (docker/entrypoint.sh)
Added directory creation with proper ownership before services start:
```sh
mkdir -p ${FRONTEND_DIR}/.next/cache
chown -R www-data:www-data ${FRONTEND_DIR}/.next
chmod -R 775 ${FRONTEND_DIR}/.next
```

### 3. Made start-nextjs.sh more robust (docker/start-nextjs.sh)
Added fallback directory creation and removed the non-functional `chown` call that couldn't work when running as `www-data`.

## Trade-offs
- Using a named volume for `.next` means the build cache is isolated from the host. This is the same pattern used for `node_modules`.
- The `.next` directory contents won't be visible on the host filesystem, but this is generally not needed for development.

## Testing Notes
After applying the fix:
1. Stop the container: `docker-compose down`
2. Remove the old volume: `docker volume rm selfmx_frontend_next_cache` (if it exists)
3. Rebuild and start: `docker-compose up --build -d`
4. Verify frontend loads at configured port
