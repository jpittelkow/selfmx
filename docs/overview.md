# selfmx Documentation Overview

**Main documentation hub for AI assistants.**

selfmx is a self-hosted email application. Instead of traditional IMAP/SMTP, it uses email providers (Mailgun, with more planned) to handle domain DNS, inbound routing via webhooks, and outbound sending via API. Emails are stored in the database, indexed with Meilisearch, and served through a modern Gmail-style email client with labels, conversation threading, and full-text search. It supports multiple domains per user, multiple addresses per domain, catchall routing, and spam filtering. Built on Laravel 11 (PHP 8.3+) + Next.js 16 (React 18, TypeScript) with SQLite as the default database, running in a single Docker container.

## Customization

- [Customization Checklist](customization-checklist.md) - Step-by-step guide to customize for your project

## Documentation Index

### AI Development (Start Here)
- [AI Development Guide](ai/README.md) - **Start here** - context loading, workflows, patterns, recipes
- [Quick Reference](quick-reference.md) - Fast lookup: structure, commands, conventions, gotchas

### Architecture & Features
- [Architecture](architecture.md) - Architecture Decision Records (ADRs) with key file references
- [Compliance Templates](compliance/README.md) - SOC 2, ISO 27001, and security policy templates for customization
- [Features](features.md) - Core functionality (auth, notifications, LLM, backup)
- [Backup & Restore](backup.md) - **Backup hub**: user guide, admin settings, developer docs, key files, recipes, patterns
- [Roadmaps & Plans](roadmaps.md) - Development roadmaps and journal entries

### Technical Reference
- [Development](development.md) - Dev setup, tooling, configuration
- [Docker Configuration](docker.md) - Container setup and configuration
- [API Reference](api-reference.md) - REST API documentation

### Other
- [User Documentation](user-docs.md) - End-user guides

---

*For user-facing documentation, see [README.md](../README.md)*
