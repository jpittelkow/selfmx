# ADR-001: Technology Stack

## Status

Accepted

## Date

2026-01-24

## Context

selfmx is a starter application framework for AI to develop other apps, designed to be:
- Self-hosted with minimal external dependencies
- Flexible for building various applications on top of
- Enterprise-grade with robust authentication and notification systems
- AI-ready with multi-LLM orchestration capabilities

We need to select technologies that support these goals while maintaining:
- Developer productivity
- Community support and longevity
- Performance and scalability
- Ease of deployment

## Decision

We will use the following technology stack:

### Backend: Laravel 11 (PHP 8.3+)

**Rationale:**
- Mature framework with excellent documentation
- Built-in support for authentication, queues, scheduling, and caching
- Strong ORM (Eloquent) with database-agnostic migrations
- Laravel Sanctum for SPA authentication
- Laravel Socialite for SSO integration
- Large ecosystem of packages

### Frontend: Next.js 14+ (React 18, TypeScript)

**Rationale:**
- React's component model for building complex UIs
- TypeScript for type safety and better DX
- Next.js App Router for modern routing patterns
- Server-side rendering capabilities
- Strong community and ecosystem (shadcn/ui, Tailwind)

### Database: SQLite (default), MySQL, PostgreSQL

**Rationale:**
- SQLite requires zero configuration for self-hosting
- SQLite is embedded, reducing deployment complexity
- Migration path to MySQL/PostgreSQL for scale
- Laravel's database abstraction makes switching trivial

### Deployment: Single Docker Container

**Rationale:**
- Simplified deployment for self-hosting
- All services (Nginx, PHP-FPM, Node, Queue Worker) in one container
- Consistent environment across development and production
- Easy to distribute and version

### State Management: Zustand

**Rationale:**
- Lightweight alternative to Redux
- Simple API with TypeScript support
- No boilerplate or providers required
- Works well with React Query for server state

### Styling: Tailwind CSS

**Rationale:**
- Utility-first approach for rapid development
- Built-in dark mode support
- Small bundle size with PurgeCSS
- Consistent design system

## Consequences

### Positive

- Developers familiar with Laravel or React can contribute quickly
- Single container deployment simplifies operations
- SQLite default makes self-hosting trivial
- TypeScript catches errors at compile time
- Strong typing across frontend improves maintainability

### Negative

- PHP requires specific hosting environment (solved by Docker)
- Next.js adds complexity compared to pure SPA
- SQLite has limitations for high-concurrency writes
- Two different languages (PHP + TypeScript) to maintain

### Neutral

- Need to maintain API contracts between frontend and backend
- Developers need to understand both ecosystems

## Related Decisions

- [ADR-009: Docker Single-Container Architecture](./009-docker-single-container.md)
- [ADR-010: Database Abstraction Strategy](./010-database-abstraction.md)

## Notes

The reference implementations (Housarr and DanaVision) successfully use Laravel + React, validating this stack for production use.
