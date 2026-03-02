# Using selfmx as Your Starting Point

selfmx is designed to be forked and customized. This guide helps you get started with your own project based on selfmx.

> **Starting a new project with AI?** Say **"Get cooking"** to your AI assistant and it will walk you through a guided setup wizard — app name, colors, fonts, which features to keep, auth model, database, and more. The setup is broken into 3 tiers so you can pause and resume at any time. Full guide: [docs/ai/recipes/setup-new-project.md](docs/ai/recipes/setup-new-project.md).

## What You're Getting

A complete full-stack application framework with enterprise-grade features ready to use:

**Authentication & Users**
- Email/password authentication with Laravel Sanctum
- SSO via OAuth2/OIDC (Google, GitHub, Microsoft, Apple, Discord, GitLab)
- Two-factor authentication (TOTP + recovery codes)
- Passkeys (WebAuthn/FIDO2) for passwordless login
- User groups and granular permissions

**Notifications**
- 10+ channels: Email, Telegram, Discord, Slack, SMS (Twilio/Vonage/SNS), Matrix, ntfy, Web Push, In-App
- Customizable notification templates
- Per-user channel preferences

**AI/LLM Integration**
- Multi-provider support: Claude, OpenAI, Gemini, Ollama, AWS Bedrock, Azure OpenAI
- Three modes: Single, Aggregation, Council (consensus)
- Model discovery from provider APIs

**Infrastructure**
- Single Docker container (Nginx + PHP-FPM + Next.js via Supervisor)
- SQLite default, supports MySQL/PostgreSQL/Supabase
- Full-text search with Meilisearch
- Backup/restore with local, S3, SFTP, Google Drive destinations
- PWA support with offline capability

**Observability**
- Audit logging with real-time streaming
- Application logging with correlation IDs
- Suspicious activity detection

**Payments (Optional)**
- Stripe Connect integration with 1% platform application fee
- Payment processing with destination charges
- Connect onboarding (OAuth) for fork operators
- Payment history with admin view
- Webhook handling with idempotency
- Usage tracking integration for payment events

## Philosophy

selfmx is **opinionated but modular**:

- Architecture decisions are documented in `docs/adr/` (25 ADRs)
- Features can be removed if you don't need them
- Patterns and structure should remain consistent
- Code follows established conventions (see `docs/ai/patterns/`)

## For AI-Assisted Development

This codebase is **optimized for AI coding assistants**. The documentation structure helps AI understand and work with the code effectively.

**Key resources for AI:**

| Location | Purpose |
|----------|---------|
| `docs/ai/README.md` | Development workflows and quick links |
| `docs/ai/context-loading.md` | Which files to read for each task type |
| `docs/ai/recipes/` | 47 step-by-step implementation guides |
| `docs/ai/patterns/` | Code patterns with copy-paste examples |
| `docs/ai/anti-patterns/` | Common mistakes to avoid |

### IDE-Specific Configuration

selfmx includes AI configuration for multiple IDEs:

| IDE | Configuration File | Auto-loads |
|-----|-------------------|------------|
| Cursor | `.cursor/rules/*.mdc` | Yes |
| GitHub Copilot | `.github/copilot-instructions.md` | Yes |
| Windsurf | `.windsurfrules` | Yes |
| Other AI tools | Point to `docs/ai/README.md` | Manual |

All configurations contain the same core rules - choose the IDE that works best for you.

**Recommended workflow:**

1. Tell your AI assistant to read `docs/ai/README.md`
2. Describe what you want to build
3. The AI will find the relevant recipe and context files
4. Follow the established patterns

**Example prompt:**
> "I want to add a new notification channel. Please read docs/ai/README.md and find the relevant recipe, then implement it."

## First Steps

### 1. Clone and Run

```bash
git clone https://github.com/selfmx/selfmx.git my-project
cd my-project
docker-compose up -d
```

Access at http://localhost:8080. The first user to register becomes admin.

### 2. Explore the Application

- Create an account and explore the features
- Check Configuration pages (admin-only) to see what's configurable
- Review the documentation structure in `docs/`

### 3. Customize for Your Project

Follow the [Customization Checklist](docs/customization-checklist.md) to systematically update:

- Branding (app name, short name)
- Environment configuration
- Remove features you don't need
- Add your own features

### 4. Start Building

Use the AI development guide (`docs/ai/README.md`) to add your own features. The recipes cover common tasks like:

- Adding API endpoints
- Adding configuration pages
- Adding notification channels
- Adding dashboard widgets
- And 43 more...

## What to Keep

**Always keep:**
- `docs/ai/` - The AI development guide works for any features you add
- AI IDE configs (`.cursor/rules/`, `.github/copilot-instructions.md`, `.windsurfrules`) - Keep the ones for IDEs you use
- Core authentication - Even if you simplify, the base auth is solid
- Docker setup - Single container deployment is convenient
- Audit logging - Useful for any application

**Add your own:**
- Recipes in `docs/ai/recipes/` for patterns specific to your app
- Journal entries in `docs/journal/` for your development history
- ADRs in `docs/adr/` for your architecture decisions

## What to Remove (If Needed)

If you don't need certain features, you can remove them:

| Feature | Files to Remove |
|---------|-----------------|
| LLM/AI | `backend/app/Services/LLM/`, `backend/config/llm.php`, `frontend/app/(dashboard)/configuration/ai/` |
| Specific notification channels | Individual files in `backend/app/Services/Notifications/Channels/` |
| Backup remote destinations | Individual files in `backend/app/Services/Backup/Destinations/` |
| PWA | `frontend/public/sw.js`, `frontend/public/manifest.json`, `frontend/lib/use-install-prompt.ts` |
| Payments/Stripe | `backend/app/Services/Stripe/`, `backend/config/stripe.php`, `backend/app/Http/Controllers/Api/Stripe*.php`, `backend/app/Models/Payment.php`, `backend/app/Models/StripeCustomer.php`, `backend/app/Models/StripeWebhookEvent.php`, migrations (`*stripe*`, `*payments*`), `frontend/lib/stripe.ts`, `frontend/app/(dashboard)/configuration/stripe/`, `frontend/app/(dashboard)/configuration/payments/` |

See the [Customization Checklist](docs/customization-checklist.md) for detailed guidance.

## Documentation Structure

```
docs/
├── overview.md              # Main documentation hub
├── ai/                      # AI development guide (start here)
│   ├── README.md            # Quick start for AI assistants
│   ├── context-loading.md   # Which files to read per task
│   ├── patterns/            # Code patterns
│   ├── anti-patterns/       # What to avoid
│   └── recipes/             # 47 step-by-step guides
├── adr/                     # Architecture Decision Records
├── features.md              # Feature documentation
├── architecture.md          # Architecture overview
├── roadmaps.md              # Development plans
├── journal/                 # Development history
└── quick-reference.md       # Commands, structure, conventions
```

## Getting Help

- Read the documentation in `docs/`
- Check existing recipes for similar tasks
- Review ADRs for architectural context
- Look at existing implementations as examples

## License

selfmx is MIT licensed. You can use it for any purpose, including commercial projects.

**Stripe module exception:** The Stripe/payments files are dual-licensed under the selfmx Commercial License. Using them via Stripe Connect is free; direct Stripe usage requires a commercial license. See `backend/app/Services/Stripe/LICENSE.md` for details.
