# selfmx

Self-hosted email made simple. Manage your own domains, send and receive email through provider APIs (Mailgun, with more coming), and keep full control of your data — all in a single Docker container.

## Why selfmx?

- **No SMTP/IMAP servers to manage** — Uses email provider APIs instead of running Postfix/Dovecot
- **Works behind NAT and firewalls** — Inbound email arrives via webhooks, no port 25 needed
- **Your data stays yours** — All emails stored in your database, fully searchable, fully backed up
- **AI-powered** — Thread summaries, smart categorization, priority inbox, and reply suggestions

## Features

**Email**
- Inbound email via provider webhooks with HMAC signature verification
- Outbound sending with rich text compose (TipTap editor)
- Reply, reply-all, forward with quoted messages
- Gmail-style conversation threading (In-Reply-To / References / subject matching)
- Gmail-style labels — flexible tagging instead of rigid folders
- Drafts with auto-save, per-mailbox signatures, scheduled send
- Full-text search via Meilisearch with filters (date, attachment, from, to, label)
- Delivery status tracking (delivered, bounced, failed)
- Automatic contact extraction and management

**AI Integration**
- Email summarization and thread summaries
- Smart categorization and auto-labeling
- Priority inbox with AI-scored importance
- Smart reply suggestions
- Powered by Claude, OpenAI, Gemini, or Ollama

**Platform**
- Single Docker container deployment (Nginx + PHP-FPM + Next.js)
- Multi-user with full tenant isolation
- User management with email/password, SSO, 2FA, and passkeys
- Notifications via email, Telegram, Discord, Slack, SMS, push, and in-app
- Full backup and restore to S3, SFTP, or Google Drive
- Mobile-responsive Progressive Web App

## Tech Stack

| Layer | Technology |
|-------|-----------|
| Backend | Laravel 11 (PHP 8.3+) |
| Frontend | Next.js 16, React 18, TypeScript |
| Database | SQLite (default), MySQL, PostgreSQL |
| Search | Meilisearch |
| UI | shadcn/ui + Tailwind CSS |
| Container | Docker (single container via Supervisor) |

## Getting Started

```bash
# Clone and start
git clone https://github.com/jpittelkow/selfmx.git
cd selfmx
docker-compose up -d

# Access at http://localhost:8080
# The first user to register becomes admin
```

Configure your email provider (Mailgun) in **Configuration > Email Provider**, add your domains in **Configuration > Email Domains**, and create mailboxes in **Configuration > Mailboxes**.

## Documentation

- [Overview](docs/overview.md) — Documentation hub
- [Architecture](docs/architecture.md) — Design decisions (ADRs)
- [Email Architecture](docs/adr/027-email-hosting-architecture.md) — How email sending/receiving works
- [API Reference](docs/api/) — REST API documentation
- [Development Guide](docs/development.md) — Local setup and contribution
- [Docker Guide](docs/docker.md) — Deployment and configuration
- [Backup & Restore](docs/backup.md) — Backup configuration

## Roadmap

All 6 phases are complete. See the full [roadmap](docs/plans/email-app-roadmap.md).

**Highlights:**
- Spam filtering with per-user allow/block lists
- Email rules and filters (auto-label, auto-forward, auto-archive)
- Scheduled send and snooze
- Real-time push notifications on new email via Laravel Reverb
- Multiple providers (Mailgun, AWS SES, SendGrid, Postmark)
- Email import (mbox/eml upload)
- Keyboard shortcuts and command palette

## Built With

selfmx is built on [Sourdough v0.10.3](https://github.com/Sourdough-start/sourdough), a full-stack starter framework for Laravel + Next.js applications.

## License

MIT License — see [LICENSE](LICENSE) for details.
