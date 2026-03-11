# Code Patterns

Follow these patterns for consistency across the codebase. Read only the files relevant to your task.

## Backend Patterns

| Pattern | File | When to Use |
|---------|------|-------------|
| Controller + ApiResponseTrait + Pagination | [controller.md](controller.md) | Creating API controllers |
| Form Request | [form-request.md](form-request.md) | Validating API input |
| Resource | [resource.md](resource.md) | Formatting JSON responses |
| Service + Channel/Provider | [service.md](service.md) | Business logic, notification channels |
| Route Definition | [route-definition.md](route-definition.md) | Defining API routes |
| User Disable | [user-disable.md](user-disable.md) | Enable/disable user accounts |
| First User Admin | [first-user-admin.md](first-user-admin.md) | Registration and SSO flows |
| Admin Authorization | [admin-authorization.md](admin-authorization.md) | Protecting last admin |
| Audit Service | [audit-service.md](audit-service.md) | Logging user actions |
| Access Log Service (HIPAA) | [access-log-service.md](access-log-service.md) | Logging data access |
| Permission Checking | [permission-checking.md](permission-checking.md) | Backend + frontend permission checks |
| Settings Overview | [settings-overview.md](settings-overview.md) | Which settings system to use (decision flowchart) |
| Setting Service | [setting-service.md](setting-service.md) | System-wide settings with env fallback |
| Search Service | [search-service.md](search-service.md) | Full-text search with Meilisearch |
| Email Provider | [email-provider.md](email-provider.md) | Implementing email providers with capability interfaces |
| Email Template Service | [email-template-service.md](email-template-service.md) | Rendering and sending emails |
| Notification Template Service | [notification-template-service.md](notification-template-service.md) | Notification templates per channel |
| Backup & Restore | [backup-restore.md](backup-restore.md) | Backup operations and destinations |
| Storage Settings | [storage-settings.md](storage-settings.md) | Storage provider configuration |
| Scheduled Task Service | [scheduled-task-service.md](scheduled-task-service.md) | Manual run of scheduled commands |
| LLM Model Discovery | [llm-model-discovery.md](llm-model-discovery.md) | AI provider model discovery |
| Logging | [logging.md](logging.md) | Application logging |
| User Password | [user-password.md](user-password.md) | Password hashing (hashed cast) |

## Frontend Patterns

| Pattern | File | When to Use |
|---------|------|-------------|
| Config/Settings Page | [config-page.md](config-page.md) | Building settings and list pages |
| API Calls | [api-calls.md](api-calls.md) | Making API requests, error extraction |
| Dashboard Widget | [dashboard-widget.md](dashboard-widget.md) | Adding dashboard widgets |
| Responsive & Mobile-First | [responsive.md](responsive.md) | Mobile-first CSS, responsive layouts |
| Global Components | [components.md](components.md) | Reusable components, charts, redirects |
| UI Components | [ui-patterns.md](ui-patterns.md) | CollapsibleCard, ProviderIcon, Help, Auth, PWA, Avatar, Loading, Animations, Typography, Notifications, Safe Area |
| Interactive Patterns | [interactive.md](interactive.md) | Test connection, file download, confirmation dialog |

## Cross-Cutting Patterns

| Pattern | File | When to Use |
|---------|------|-------------|
| Error Handling | [error-handling.md](error-handling.md) | Backend + frontend error handling |
| Security | [security.md](security.md) | SSRF, webhooks, passwords, OAuth, filename validation |

## Naming Conventions

| Type | Pattern | Example |
|------|---------|---------|
| Controller | `{Feature}Controller.php` | `ExampleController.php` |
| Service | `{Feature}Service.php` | `ExampleService.php` |
| Model | `{Singular}.php` | `Example.php` |
| FormRequest | `{Action}{Model}Request.php` | `StoreExampleRequest.php` |
| Resource | `{Model}Resource.php` | `ExampleResource.php` |
| Channel | `{Provider}Channel.php` | `TelegramChannel.php` |
| Frontend page | `page.tsx` in route folder | `app/(dashboard)/example/page.tsx` |
| Frontend component | `kebab-case.tsx` | `example-card.tsx` |
| Migration | `{timestamp}_create_{table}_table.php` | `2024_01_01_000000_create_examples_table.php` |
| Hook | `use-{feature}.ts` | `use-permission.ts` |
| Test (PHP) | `{Feature}Test.php` | `AuthTest.php` |
| Test (TS) | `{feature}.test.ts(x)` | `api.test.ts` |
| Factory | `{Model}Factory.php` | `UserFactory.php` |
| Middleware | `{PascalCase}.php` | `AddCorrelationId.php` |
| Helper | `{Feature}Helper.php` | `QueryHelper.php` |
