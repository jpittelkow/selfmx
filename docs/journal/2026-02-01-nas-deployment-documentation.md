# NAS Deployment Documentation - February 1, 2026

## Overview

Added comprehensive documentation for deploying selfmx on NAS systems (Unraid, Synology, TrueNAS) that use Docker GUI templates instead of docker-compose.

## Problem Discovered

When deploying on Unraid using the Docker GUI, users experienced:

1. **"attempt to write a readonly database"** error when creating the first user
2. **Meilisearch "Permission denied"** errors on startup
3. Migrations appeared successful but database file was 0 bytes

## Root Cause

The docker-compose files automatically set `DB_DATABASE=/var/www/html/data/database.sqlite`, but NAS Docker templates don't read docker-compose files. Without this environment variable:

- Laravel defaults to `database_path('database.sqlite')` which resolves to `/var/www/html/backend/database/database.sqlite`
- Migrations write to this non-mounted internal path
- The mounted volume at `/var/www/html/data/` remains empty
- PHP-FPM (running as www-data) then fails to write to the database

The key insight was that migrations ran as root during entrypoint and succeeded, but the database file on the mounted volume was 0 bytes - indicating the mount wasn't being used.

## Solution

Document all required environment variables for NAS deployments, emphasizing that `DB_DATABASE` must be explicitly set.

## Files Changed

- `docs/docker.md` - Added "NAS Deployment" section with:
  - Required environment variables table
  - Volume mapping requirements
  - PUID/PGID configuration
  - Unraid template example
  - Troubleshooting guide for common errors
- `backend/.env.example` - Added comment clarifying Docker vs local paths

## Testing Notes

Verified fix on Unraid 6.x with the following configuration:
- PUID=99, PGID=100 (Unraid defaults)
- Volume mappings to `/mnt/user/appdata/selfmx/`
- `DB_DATABASE=/var/www/html/data/database.sqlite` environment variable

## Future Considerations

- Consider adding an Unraid Community Applications template XML file
- Could add startup validation in entrypoint to warn if DB_DATABASE points to non-mounted path
- May want to add similar documentation for other container orchestrators (Portainer, etc.)
