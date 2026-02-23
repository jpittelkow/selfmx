# Development Configuration

Development setup, tooling, and configuration:

- [Developer Guide](dev/README.md) - Complete development setup, project structure, testing, contributing guidelines

## Prerequisites

- Docker and Docker Compose
- Node.js 20+ (for frontend development)
- PHP 8.3+ and Composer (for backend development)

## Local Setup

```bash
# Clone
git clone https://github.com/Sourdough-start/sourdough.git
cd sourdough

# Backend
cd backend
composer install
cp .env.example .env
php artisan key:generate
touch database/database.sqlite
php artisan migrate
php artisan serve

# Frontend (new terminal)
cd frontend
npm install
npm run dev
```

## Docker Setup

```bash
# Copy environment file and configure port
cp .env.example .env

# Edit .env to change port (default is 8080)
# APP_PORT=8081  # Use a different port if needed

docker-compose up -d
# Access at http://localhost:8080 (or your configured port)
```

### Running Multiple Instances

If you're running multiple apps, change the port and container name in `.env`:

```env
APP_PORT=8081
CONTAINER_NAME=sourdough-project-name
```

## Settings Import (Env to Database)

To copy current environment variables into the database (e.g. after adding new schema keys or migrating from env-only):

```bash
# Import all groups defined in settings-schema
php artisan settings:import-env

# Import only mail settings
php artisan settings:import-env --group=mail
```

Imported values are stored in `system_settings`; sensitive keys are encrypted. Config is applied at next boot via ConfigServiceProvider.

## Configuration

Sourdough is configured via environment variables. See `.env.example` for all options. Many settings can also be managed in the database (see [ADR-014](adr/014-database-settings-env-fallback.md)); database values override env when present. **Settings that must stay in env** (bootstrap/security): `APP_KEY`, `APP_ENV`, `APP_DEBUG`, `DB_*`, `LOG_*`, `CACHE_STORE`. See [Env to Database Roadmap](plans/env-to-database-roadmap.md#23-settings-that-must-stay-in-env).

### Required Variables

| Variable | Description |
|----------|-------------|
| `APP_URL` | Application URL. All other URL-related variables are derived from this. |

### Auto-Generated / Auto-Derived Variables

These are handled automatically in Docker and do not need to be set manually:

| Variable | Behavior | When to Override |
|----------|----------|-----------------|
| `APP_KEY` | Auto-generated on first boot, persisted in the data volume | Migrating from an existing deployment |
| `MEILI_MASTER_KEY` | Auto-generated on first boot, persisted in the data volume | Running Meilisearch externally |
| `FRONTEND_URL` | Defaults to `APP_URL` | Local dev (non-Docker) where frontend and backend run on different ports |
| `SANCTUM_STATEFUL_DOMAINS` | Auto-derived from `APP_URL` hostname | Multiple domains need cookie-based auth. If incorrect, login will silently fail with 401 on subsequent requests. |

### Application Key (APP_KEY)

Laravel uses `APP_KEY` for all symmetric encryption — the `Crypt` facade, `encrypt()`/`decrypt()` helpers, signed cookies, session data, and password reset tokens. Without a valid key, none of these features work.

#### How it's generated

**Docker:** The entrypoint ([`docker/entrypoint.sh`](../docker/entrypoint.sh)) auto-generates a key on first boot via `php artisan key:generate --show`, saves it to `data/.app_key`, and loads it on every subsequent boot. You do not need to set it manually.

**Local development (non-Docker):** Running `php artisan key:generate` during setup writes the key directly into `backend/.env`. This is included in the [Local Setup](#local-setup) steps above.

#### Specifying your own key

If you want to provide your own key instead of auto-generating one, set `APP_KEY` in your environment before the application starts:

```env
# In .env or as a Docker environment variable
APP_KEY=base64:your-base64-encoded-key-here
```

When the Docker entrypoint detects that `APP_KEY` is already set, it skips generation entirely and uses your value as-is.

To generate a key without writing it to `.env` (useful for populating a secrets manager or Docker env var):

```bash
cd backend
php artisan key:generate --show
# Output: base64:xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx
```

Copy the full output (including the `base64:` prefix) into your environment variable.

#### Key rotation

Rotating `APP_KEY` has significant consequences:

- All existing encrypted data (values stored with `Crypt::encrypt()`) becomes **permanently unreadable**
- All active sessions are invalidated — users will be logged out
- Signed cookies and password reset tokens are invalidated
- Laravel has **no built-in re-encryption migration** — you must decrypt data with the old key and re-encrypt with the new one yourself

Only rotate the key if it has been compromised. See [ADR-015](adr/015-env-only-settings.md) and the [Cryptographic Controls](compliance/iso27001/cryptographic-controls.md) policy for more on key management.

#### Migrating to a new deployment

To preserve encrypted data when moving to a new server or container:

1. Copy the key from the old deployment — either from `data/.app_key` (Docker) or `backend/.env` (local)
2. Set it as `APP_KEY` in the new environment **before first boot**
3. The entrypoint will detect the existing key and skip generation

### Optional Features

Enable features by setting their configuration:

```env
# Enable Google SSO
GOOGLE_CLIENT_ID=your-client-id
GOOGLE_CLIENT_SECRET=your-secret

# Enable Claude AI
ANTHROPIC_API_KEY=your-key

# Enable Telegram notifications
TELEGRAM_BOT_TOKEN=your-token
```

## Backend Configuration (`backend/config/`)

- `app.php` - Application configuration
- `auth.php` - Authentication settings
- `llm.php` - LLM provider configuration
- `notifications.php` - Notification channel configuration
- `backup.php` - Backup system settings
- `database.php` - Database connection settings
- `sso.php` - SSO provider configuration

## Frontend Configuration

- `frontend/next.config.js` - Next.js configuration
- `frontend/tailwind.config.ts` - Tailwind CSS configuration
- `frontend/tsconfig.json` - TypeScript configuration
- `frontend/vitest.config.ts` - Vitest test configuration

## Environment Variables

- `.env.example` - Root environment template
- `backend/.env.example` - Backend-specific environment template

## Testing Configuration

- `playwright.config.ts` - E2E test configuration
- `backend/phpunit.xml` - PHPUnit/Pest configuration

## Security Scanning

Automated security scanning (SAST) is integrated into the CI/CD pipeline and available for local development.

### Local Security Checks

Run all security scans locally before pushing:

```bash
# Run all security checks
./scripts/security-scan.sh

# Skip Semgrep for faster iteration
./scripts/security-scan.sh --quick

# Auto-fix ESLint issues
./scripts/security-scan.sh --fix
```

### Individual Tools

**Backend (PHPStan):**
```bash
cd backend
composer phpstan
```

**Frontend (ESLint Security):**
```bash
cd frontend
npm run lint
```

**Full Codebase (Semgrep):**
```bash
# Install: pip install semgrep
semgrep scan --config=p/security-audit --config=p/php-laravel --config=p/typescript .
```

### CI Integration

Security scanning runs automatically on push/PR to master:
- PHPStan (backend static analysis)
- ESLint with security plugin (frontend)
- Semgrep (OWASP, PHP-Laravel, TypeScript rules)

See [`.github/workflows/ci.yml`](../.github/workflows/ci.yml) for the full CI configuration. The [Release workflow](../.github/workflows/release.yml) can be triggered two ways: manually via Actions > Release > Run workflow (select version bump type), or automatically by pushing a `v*` tag (e.g. `git tag v1.3.0 && git push --tags`). Both paths sync version files, create a GitHub Release, and push the Docker image to GHCR.
