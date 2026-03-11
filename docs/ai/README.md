# AI Development Guide

Quick-start guide for AI assistants developing on Sourdough.

## Context Loading

**Identify your task type, then load the relevant files from [context-loading.md](context-loading.md).**

For quick lookup, the task-type table is also in [CLAUDE.md](../../CLAUDE.md#task-type-file-lookup).

## Development Workflow

```
                     ┌──────────────┐
                     │ 1. Check     │
                     │ Roadmaps     │
                     └──────┬───────┘
                            ▼
┌──────────────┐     ┌──────────────┐     ┌──────────────┐
│ 2. Load      │────▶│ 3. Plan      │────▶│ 4. Work      │
│ Context      │     │ (+ recipes/  │     │ (follow      │
│ (read files) │     │  patterns)   │     │  the plan)   │
└──────────────┘     └──────────────┘     └──────────────┘
```

## Planning Requirements

When creating a plan for implementation:

1. **Identify applicable recipes** - Check the recipes table below; name the specific recipes in your plan
2. **Reference patterns** - Note which patterns from [patterns/README.md](patterns/README.md) apply
3. **Link to ADRs** - Include relevant architectural decisions
4. **Include context loading** - Reference which files from [context-loading.md](context-loading.md) will be read

Example plan reference:

> This implementation will follow [add-config-page.md](recipes/add-config-page.md) recipe and use the SettingService pattern from patterns/setting-service.md. Relevant ADR: [ADR-014](../adr/014-database-settings-env-fallback.md).

## Recipes

| Task | Recipe |
|------|--------|
| **Full-Stack Feature** | [add-full-stack-feature.md](recipes/add-full-stack-feature.md) |
| **Set Up New Project** | [setup-new-project.md](recipes/setup-new-project.md) (master index) |
| — Tier 1: Identity & Branding | [setup-identity-branding.md](recipes/setup-identity-branding.md) |
| — Tier 2: Features & Auth | [setup-features-auth.md](recipes/setup-features-auth.md) |
| — Tier 3: Infrastructure & Repo | [setup-infrastructure-repo.md](recipes/setup-infrastructure-repo.md) |
| Commit, Push & Release | [commit-and-release.md](recipes/commit-and-release.md) |
| Code Review | [code-review.md](recipes/code-review.md) |
| Add API Endpoint | [add-api-endpoint.md](recipes/add-api-endpoint.md) |
| Add Admin-Protected Action | [add-admin-protected-action.md](recipes/add-admin-protected-action.md) |
| Add Config Page | [add-config-page.md](recipes/add-config-page.md) |
| Add Settings Page | [add-settings-page.md](recipes/add-settings-page.md) |
| Add UI Component | [add-ui-component.md](recipes/add-ui-component.md) |
| Add Collapsible Section | [add-collapsible-section.md](recipes/add-collapsible-section.md) |
| Add Provider Icon | [add-provider-icon.md](recipes/add-provider-icon.md) |
| Add Notification Channel | [add-notification-channel.md](recipes/add-notification-channel.md) |
| Configure Novu | [configure-novu.md](recipes/configure-novu.md) |
| Trigger Notifications | [trigger-notifications.md](recipes/trigger-notifications.md) |
| Add Dashboard Widget | [add-dashboard-widget.md](recipes/add-dashboard-widget.md) |
| Add LLM Provider | [add-llm-provider.md](recipes/add-llm-provider.md) |
| Add SSO Provider | [add-sso-provider.md](recipes/add-sso-provider.md) |
| Add Backup Destination | [add-backup-destination.md](recipes/add-backup-destination.md) |
| Extend Backup & Restore | [extend-backup-restore.md](recipes/extend-backup-restore.md) |
| Add Storage Provider | [add-storage-provider.md](recipes/add-storage-provider.md) |
| Add Email Provider | [add-email-provider.md](recipes/add-email-provider.md) |
| Add Email Template | [add-email-template.md](recipes/add-email-template.md) |
| Add Notification Template | [add-notification-template.md](recipes/add-notification-template.md) |
| Keep Notification Template Variables Up to Date | [keep-notification-template-variables-up-to-date.md](recipes/keep-notification-template-variables-up-to-date.md) |
| Extend Logging | [extend-logging.md](recipes/extend-logging.md) |
| Add Access Logging (HIPAA) | [add-access-logging.md](recipes/add-access-logging.md) |
| Add Searchable Model | [add-searchable-model.md](recipes/add-searchable-model.md) |
| Add Tests | [add-tests.md](recipes/add-tests.md) |
| Make Responsive | [make-component-responsive.md](recipes/make-component-responsive.md) |
| Assign User to Groups | [assign-user-to-groups.md](recipes/assign-user-to-groups.md) |
| Create Custom Group | [create-custom-group.md](recipes/create-custom-group.md) |
| Add New Permission | [add-new-permission.md](recipes/add-new-permission.md) |
| Add Auditable Action | [add-auditable-action.md](recipes/add-auditable-action.md) |
| Trigger Audit Logging | [trigger-audit-logging.md](recipes/trigger-audit-logging.md) |
| Add Searchable Page | [add-searchable-page.md](recipes/add-searchable-page.md) |
| Add Help Article | [add-help-article.md](recipes/add-help-article.md) |
| Add Configuration Menu Item | [add-configuration-menu-item.md](recipes/add-configuration-menu-item.md) |
| Add PWA Install Prompt | [add-pwa-install-prompt.md](recipes/add-pwa-install-prompt.md) |
| Setup Stripe | [setup-stripe.md](recipes/setup-stripe.md) |
| Add Payment Flow | [add-payment-flow.md](recipes/add-payment-flow.md) |
| Handle Stripe Webhooks | [handle-stripe-webhooks.md](recipes/handle-stripe-webhooks.md) |
| Stripe Connect Onboarding | [stripe-connect-onboarding.md](recipes/stripe-connect-onboarding.md) |
| Add Color Theme | [add-theme.md](recipes/add-theme.md) |

## Common Gotchas

See [CLAUDE.md Gotchas](../../CLAUDE.md#gotchas) (always loaded) for the full list. Key ones:

- **Global components** - NEVER duplicate logic across pages. See [Components Pattern](patterns/components.md).
- **Schema-backed settings** - Use **SettingService**, not `SystemSetting::get`/`set` directly. See [ADR-014](../adr/014-database-settings-env-fallback.md).
- **Admin is group-based** - Not an `is_admin` column. See CLAUDE.md gotchas.

See also: [Anti-Patterns](anti-patterns/README.md) - Common mistakes to avoid

## Adding Tools & Dependencies

When adding new external tools or dependencies:
1. **Check [OpenAlternative.co](https://openalternative.co/)** for open-source alternatives
2. Prefer open-source tools that align with the project's self-hosted philosophy
3. Document the tool choice in the relevant ADR or journal entry

## Key Architectural Concepts

- **Single Docker container** - Nginx + PHP-FPM + Next.js via Supervisor
- **API-first** - Frontend calls backend via `/api/` proxy
- **User-scoped data** - Most tables have `user_id` column
- **Service layer** - Business logic in `Services/`, not controllers
- **Channel/Provider pattern** - Notifications, LLM, Backup all use pluggable implementations
- **Global components** - One implementation, used everywhere. No duplicated logic across pages.

## Detailed Guides

| Guide | Purpose |
|-------|---------|
| [Architecture Map](architecture-map.md) | How data flows through the application |
| [Context Loading](context-loading.md) | Full list of files to read per task type |
| [Patterns](patterns/README.md) | Code patterns with copy-paste examples |
| [Anti-Patterns](anti-patterns/README.md) | Common mistakes to avoid |
| [Recipes](recipes/) | Step-by-step guides for common tasks |

## Related Documentation

- [Quick Reference](../quick-reference.md) - Commands, structure, naming conventions
- [Architecture ADRs](../architecture.md) - Design decisions
- [Features](../features.md) - What's implemented
- [Roadmaps](../roadmaps.md) - What's planned
