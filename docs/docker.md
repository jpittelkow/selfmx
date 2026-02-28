# Docker Configuration

Single-container deployment configuration.

## Quick Start (Local Development)

```bash
cp .env.example .env
docker-compose up -d
# Access at http://localhost:8080
```

See [Key Commands](#key-commands) below for complete commands and troubleshooting.

## Key Commands

| Command | Purpose |
|---------|---------|
| `docker-compose up -d` | Start container |
| `docker-compose down` | Stop container |
| `docker-compose up -d --build` | Rebuild and start |
| `docker-compose logs -f` | View logs |
| `docker-compose exec app bash` | Shell access |

## Production Images (GHCR)

Release builds are published to GitHub Container Registry when the [Release workflow](../.github/workflows/release.yml) runs. The workflow can be triggered via Actions > Release > Run workflow (manual dispatch) or by pushing a `v*` tag (e.g. `git tag v1.3.0 && git push --tags`). Both methods sync version files and produce the same result. Images are tagged with semver (e.g. `1.2.3`), major.minor (`1.2`), major (`1`), commit SHA, and `latest`. Pull with `docker pull ghcr.io/owner/repo:latest` for the newest release, or a specific version like `docker pull ghcr.io/owner/repo:1.2.3`.

## Resource Requirements

| Resource | Minimum | Recommended | Notes |
|----------|---------|-------------|-------|
| RAM | 1 GB | 2 GB+ | Meilisearch + PHP-FPM + Node.js all share memory |
| CPU | 1 core | 2+ cores | Concurrent PHP and Node.js processes |
| Disk | 2 GB | 5 GB+ | Depends on uploaded files and backup retention |

**Meilisearch memory:** Grows with index size. For small deployments (< 10k searchable items), the embedded Meilisearch within the container works well. For large datasets, consider running Meilisearch externally by setting `MEILISEARCH_HOST` to the external URL and disabling the embedded instance.

**When to externalize services:** If memory usage exceeds 2 GB or you need to scale services independently, consider running Meilisearch and/or the queue worker as separate containers. Set `MEILISEARCH_HOST` to point to the external instance.

## Configuration Files

- [ADR-009: Docker Single-Container Architecture](adr/009-docker-single-container.md) - Architecture decision and rationale
- `docker/Dockerfile` - Main container build configuration
- `docker/supervisord.conf` - Process manager configuration (Nginx, PHP-FPM, Next.js, Meilisearch)
- `docker/nginx.conf` - Nginx reverse proxy configuration
- `docker/php.ini` - PHP runtime configuration (production)
- `docker/php-dev.ini` - PHP runtime configuration (development, with file change detection)
- `docker/entrypoint.sh` - Container initialization script
- `docker/start-nextjs.sh` - Next.js startup script
- `docker-compose.yml` - Development Docker Compose configuration
- `docker-compose.prod.yml` - Production Docker Compose configuration

## Hot Reload / File Change Detection

**Backend (PHP/Laravel):** Development uses `docker/php-dev.ini` which enables `opcache.validate_timestamps=1` so PHP detects file changes immediately.

**Frontend (Next.js):** Development mode runs `npm run dev` with hot module replacement. The entrypoint clears any production build markers to ensure dev mode.

### Windows File Watching

Docker on Windows (via WSL2/Hyper-V) doesn't reliably propagate filesystem events to containers. The project is configured with:

1. **Webpack polling** in `frontend/next.config.js` - Checks for file changes every second
2. **WATCHPACK_POLLING=true** in `docker-compose.yml` - Enables watchpack polling mode

If changes still aren't detected, clear the Next.js cache volume:

```bash
docker-compose down
docker volume rm sourdough_frontend_next_cache
docker-compose up -d
```

### SWC Binary Issues (Next.js 16+)

If Next.js fails with `Failed to load SWC binary` errors, the SWC compiler binary for Linux wasn't installed. This can happen after clearing the node_modules volume:

```bash
docker-compose exec app npm --prefix /var/www/html/frontend install @next/swc-linux-x64-gnu
docker-compose exec app chown -R www-data:www-data /var/www/html/frontend/node_modules
docker-compose restart
```

### Permission issues after npm build

If you run `npm run build` or `npm install` inside the container (e.g. `docker-compose exec app npm --prefix /var/www/html/frontend run build`), those commands run as root and create root-owned files. The Next.js process runs as `www-data`, so you may see **EACCES** on `.next/server/*` and 500 errors. Fix ownership:

```bash
docker-compose exec app chown -R www-data:www-data /var/www/html/frontend/.next
```

See the troubleshooting sections below for full troubleshooting.

## Environment Variables

Edit `.env` to configure:

| Variable | Default | Purpose |
|----------|---------|---------|
| `APP_PORT` | `8080` | Host port |
| `APP_URL` | `http://localhost` | Public URL (set to your domain when behind a reverse proxy) |
| `CONTAINER_NAME` | `sourdough-dev` | Container name |
| `TRUSTED_PROXIES` | *(empty)* | Proxy IPs or `*` for all (Cloudflare Tunnel, Traefik) |

## NAS Deployment (Unraid, Synology, TrueNAS)

When deploying on NAS systems using their Docker GUI (not docker-compose), you must manually configure environment variables and volume mappings.

### Required Environment Variables

These variables are set automatically by `docker-compose.prod.yml` but **must be added manually** in NAS Docker templates:

| Variable | Required Value | Notes |
|----------|----------------|-------|
| `DB_DATABASE` | `/var/www/html/data/database.sqlite` | **Critical** - Without this, migrations write to a non-persistent location |
| `DB_CONNECTION` | `sqlite` | Database driver |
| `APP_ENV` | `production` | Environment mode |
| `APP_URL` | `http://your-nas-ip:port` | Your access URL (use `https://yourdomain.com` if behind a reverse proxy/tunnel) |
| `TRUSTED_PROXIES` | *(empty)* | Set to `*` if using a reverse proxy or Cloudflare Tunnel |

The following are **auto-generated or auto-derived** on first boot and do not need to be set manually:

| Variable | Behavior | Override when... |
|----------|----------|-----------------|
| `APP_KEY` | Auto-generated and persisted in the data volume. See [Application Key docs](development.md#application-key-app_key) for generation, rotation, and migration details. | Migrating from an existing deployment |
| `MEILI_MASTER_KEY` | Auto-generated and persisted in the data volume | Running Meilisearch externally |
| `FRONTEND_URL` | Defaults to `APP_URL` | Frontend is on a different origin (not typical) |
| `SANCTUM_STATEFUL_DOMAINS` | Auto-derived from `APP_URL` hostname | Multiple domains need cookie-based auth |

### Required Volume Mappings

| Container Path | Host Path Example | Purpose |
|----------------|-------------------|---------|
| `/var/www/html/data` | `/mnt/user/appdata/sourdough/data` | SQLite database |
| `/var/www/html/backend/storage/app` | `/mnt/user/appdata/sourdough/storage` | File uploads, backups |
| `/var/lib/meilisearch` | `/mnt/user/appdata/sourdough/meilisearch` | Search index |

### Permission Configuration (PUID/PGID)

NAS systems often use specific user/group IDs. Set these to match your host volume ownership:

| Variable | Unraid Default | Synology Default | Purpose |
|----------|----------------|------------------|---------|
| `PUID` | `99` | `1000` | User ID for www-data |
| `PGID` | `100` | `1000` | Group ID for www-data |

**Finding your IDs:** On Unraid, `nobody:users` is typically `99:100`. On Synology, check with `id your-username`.

### Unraid Template Example

In Unraid's Docker tab, add these variables:

```
APP_ENV=production
APP_URL=http://192.168.1.100:8080
DB_CONNECTION=sqlite
DB_DATABASE=/var/www/html/data/database.sqlite
PUID=99
PGID=100
```

`APP_KEY`, `MEILI_MASTER_KEY`, `FRONTEND_URL`, and `SANCTUM_STATEFUL_DOMAINS` are all handled automatically. See the table above for when you might need to override them.

### Troubleshooting NAS Deployments

**"attempt to write a readonly database" error:**
- Ensure `DB_DATABASE=/var/www/html/data/database.sqlite` is set as an environment variable
- Check volume permissions: `ls -la /mnt/user/appdata/sourdough/data/`
- Verify PUID/PGID match host directory ownership

**Meilisearch "Permission denied" errors:**
- **Most common cause:** Meilisearch tries to create `dumps/` and `snapshots/` directories in its working directory on startup. Without an explicit working directory, Supervisor defaults to `/`, and writing `/dumps/` as www-data is denied. Fixed by setting `directory=/var/lib/meilisearch` and explicit `--dump-dir`/`--snapshot-dir` flags in the supervisor config.
- **Earlier fix:** Supervisor's `user=` directive doesn't set HOME properly; fixed by using `su` to set `HOME=/var/lib/meilisearch`.
- If you're running an older image, rebuild: `docker-compose up -d --build`
- For NAS volume permission issues: `chmod -R 775 /path/to/meilisearch && chown -R PUID:PGID /path/to/meilisearch`

**Migrations run but database is empty (0 bytes):**
- This means `DB_DATABASE` isn't set - Laravel is using the default path
- Add `DB_DATABASE=/var/www/html/data/database.sqlite` to environment variables
- Restart the container

**Cache "No such file or directory" errors (e.g. `file_put_contents(...cache/data/...): Failed to open stream`):**
- This happens when the file cache directory has incorrect ownership - the entrypoint runs artisan commands as root which can create root-owned files before PHP-FPM (www-data) needs to write there
- Fixed in v0.1.0+: the entrypoint now runs a second `chown`/`chmod` pass after all artisan commands
- For older images, fix manually:
  ```bash
  docker exec sourdough chown -R 99:100 /var/www/html/backend/storage/framework/cache
  docker exec sourdough chmod -R 775 /var/www/html/backend/storage/framework/cache
  ```
  (Replace `99:100` with your PUID:PGID values)

## Reverse Proxy / Cloudflare Tunnel

When running behind a reverse proxy (Cloudflare Tunnel, Traefik, Nginx proxy, Caddy, etc.), you must configure two things:

### 1. Set APP_URL to your public domain

```env
APP_URL=https://yourdomain.com
```

`FRONTEND_URL` and `SANCTUM_STATEFUL_DOMAINS` are automatically derived from `APP_URL` -- you do not need to set them separately.

**This is critical for OAuth/SSO.** The OAuth callback URL is built from `APP_URL`. If `APP_URL` is set to a private IP (e.g. `http://192.168.1.4:8080`), Google and other OAuth providers will reject the redirect URI with errors like "device_id and device_name are required for private IP".

### 2. Trust proxy headers

Set `TRUSTED_PROXIES` so Laravel correctly reads `X-Forwarded-For`, `X-Forwarded-Proto`, etc.:

```env
# Trust all proxies (simplest, works with Cloudflare Tunnel)
TRUSTED_PROXIES=*

# Or specify proxy IPs (more restrictive)
TRUSTED_PROXIES=172.16.0.0/12,192.168.0.0/16
```

Without trusted proxies, Laravel may generate HTTP URLs instead of HTTPS, break signed URLs, and report incorrect client IPs.

### Example: Cloudflare Tunnel

```env
APP_URL=https://myapp.example.com
TRUSTED_PROXIES=*
```

## Security Headers

Nginx is configured with security headers in `docker/nginx.conf`:

| Header | Value | Purpose |
|--------|-------|---------|
| `X-Frame-Options` | `SAMEORIGIN` | Prevents clickjacking |
| `X-Content-Type-Options` | `nosniff` | Prevents MIME sniffing |
| `Referrer-Policy` | `strict-origin-when-cross-origin` | Controls referrer information |
| `Permissions-Policy` | `camera=(), microphone=(), geolocation=()` | Disables sensitive browser features |
| `Content-Security-Policy` | See below | Controls resource loading |

**Note:** `X-XSS-Protection` is not set; it was removed as deprecated and no longer supported in modern browsers.

**CSP Policy:** `default-src 'self'; script-src 'self' 'unsafe-inline' 'unsafe-eval'; style-src 'self' 'unsafe-inline'; img-src 'self' data: blob:; font-src 'self' data:; connect-src 'self' ws: wss:; frame-ancestors 'self';`

Notes:
- `unsafe-inline` and `unsafe-eval` are required for Next.js/React hydration
- `ws: wss:` allows Laravel Echo/Reverb websocket connections
- `blob:` and `data:` are needed for image handling

## Meilisearch (Search Engine)

Meilisearch runs inside the main app container, managed by Supervisor alongside Nginx, PHP-FPM, and Next.js. It listens on `127.0.0.1:7700` and persists data to the `meilisearch_data` volume at `/var/lib/meilisearch`. The Docker image uses Debian (not Alpine) because Meilisearch binaries require glibc.

**Version pinning:** The Dockerfile installs a specific Meilisearch version via a build argument (`MEILISEARCH_VERSION`, default `1.34.2`) instead of the install script, for reproducible and auditable builds. To use a different version, pass `--build-arg MEILISEARCH_VERSION=x.y.z` when building the image.

**Verification commands:**

| Command | Purpose |
|---------|---------|
| `docker-compose exec app curl -f http://127.0.0.1:7700/health` | Check Meilisearch health (from inside app container) |
| `docker-compose exec app php /var/www/html/backend/artisan search:reindex` | Rebuild search indexes |
| `docker-compose logs -f app` | View app logs (includes Meilisearch output) |

| Variable | Default | Purpose |
|----------|---------|---------|
| `MEILI_MASTER_KEY` | *(auto-generated)* | API key; auto-generated and persisted in the data volume on first boot. Override only if running Meilisearch externally. Dev defaults to `masterKey`. |

### Search box not working

If the search box (Cmd+K or header search) shows errors or no results:

1. **Hard refresh** the browser (Ctrl+Shift+R or Cmd+Shift+R) to clear cached JavaScript.
2. **Reindex** search: `docker-compose exec app php /var/www/html/backend/artisan search:reindex`
3. **Verify Meilisearch** is running: `docker-compose exec app curl -f http://127.0.0.1:7700/health`
4. Ensure you are **logged in**; the search API requires authentication.
