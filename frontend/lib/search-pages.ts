/**
 * @deprecated Pages are now indexed in Meilisearch.
 * This file is kept as fallback for offline/error scenarios.
 * To add new pages, update backend/config/search-pages.php instead.
 * See docs/ai/recipes/add-searchable-page.md
 */
export interface SearchPage {
  id: string;
  title: string;
  subtitle?: string;
  url: string;
  keywords?: string[];
  adminOnly?: boolean;
}

const MAX_PAGE_RESULTS = 5;

export const SEARCH_PAGES: SearchPage[] = [
  // User pages (all users)
  {
    id: "mail",
    title: "Inbox",
    url: "/mail",
    keywords: ["inbox", "email", "mail"],
  },
  {
    id: "notifications",
    title: "Notifications",
    url: "/notifications",
    keywords: ["alerts", "messages"],
  },
  {
    id: "user-preferences",
    title: "User Preferences",
    url: "/user/preferences",
    keywords: ["settings", "profile"],
  },
  {
    id: "user-profile",
    title: "User Profile",
    url: "/user/profile",
    keywords: ["account", "avatar"],
  },
  {
    id: "user-security",
    title: "User Security",
    subtitle: "Password, 2FA, passkeys, connected accounts",
    url: "/user/security",
    keywords: ["password", "2fa", "two-factor", "mfa", "passkeys", "sso", "connected accounts"],
  },
  // Configuration pages (admin only)
  {
    id: "config-system",
    title: "Configuration > System",
    subtitle: "Application-wide settings",
    url: "/configuration/system",
    adminOnly: true,
    keywords: ["app", "general"],
  },
  {
    id: "config-branding",
    title: "Configuration > Theme & Branding",
    subtitle: "Visual customization",
    url: "/configuration/branding",
    adminOnly: true,
    keywords: ["theme", "palette", "logo"],
  },
  {
    id: "config-users",
    title: "Configuration > Users",
    subtitle: "Manage application users",
    url: "/configuration/users",
    adminOnly: true,
    keywords: ["manage users", "admin"],
  },
  {
    id: "config-groups",
    title: "Configuration > Groups",
    subtitle: "User groups and permissions",
    url: "/configuration/groups",
    adminOnly: true,
    keywords: ["groups", "permissions", "roles"],
  },
  {
    id: "config-security",
    title: "Configuration > Security",
    subtitle: "Auth and security settings",
    url: "/configuration/security",
    adminOnly: true,
    keywords: ["auth", "2fa", "password reset"],
  },
  {
    id: "config-sso",
    title: "Configuration > SSO",
    subtitle: "Single sign-on providers",
    url: "/configuration/sso",
    adminOnly: true,
    keywords: ["oauth", "google", "github", "login"],
  },
  {
    id: "config-api",
    title: "Configuration > Webhooks",
    subtitle: "Outgoing webhook endpoints",
    url: "/configuration/api",
    adminOnly: true,
    keywords: ["webhooks", "events", "automation"],
  },
  {
    id: "user-api-keys",
    title: "Security > API Keys",
    subtitle: "Manage personal API keys",
    url: "/user/security",
    adminOnly: false,
    keywords: ["api", "keys", "graphql", "token", "bearer", "rotate", "revoke"],
  },
  {
    id: "config-notifications",
    title: "Configuration > Notifications",
    subtitle: "Configure notification channels",
    url: "/configuration/notifications",
    adminOnly: true,
    keywords: ["channels", "telegram", "slack"],
  },
  {
    id: "config-email",
    title: "Configuration > Email",
    subtitle: "Email delivery configuration",
    url: "/configuration/email",
    adminOnly: true,
    keywords: ["smtp", "mail"],
  },
  {
    id: "config-email-templates",
    title: "Configuration > Email Templates",
    subtitle: "Customize system emails",
    url: "/configuration/email-templates",
    adminOnly: true,
    keywords: ["templates"],
  },
  {
    id: "config-notification-templates",
    title: "Configuration > Notification Templates",
    subtitle: "Per-type push, in-app, chat templates",
    url: "/configuration/notification-templates",
    adminOnly: true,
    keywords: ["notification", "templates", "push", "in-app", "chat"],
  },
  {
    id: "config-changelog",
    title: "Configuration > Changelog",
    subtitle: "Version history and release notes",
    url: "/configuration/changelog",
    adminOnly: false,
    keywords: ["changelog", "version", "release", "what's new", "updates"],
  },
  {
    id: "config-ai",
    title: "Configuration > AI / LLM",
    subtitle: "LLM providers and modes",
    url: "/configuration/ai",
    adminOnly: true,
    keywords: ["llm", "openai", "claude", "providers"],
  },
  {
    id: "config-storage",
    title: "Configuration > Storage",
    subtitle: "File storage configuration",
    url: "/configuration/storage",
    adminOnly: true,
    keywords: ["s3", "files", "disk"],
  },
  {
    id: "config-search",
    title: "Configuration > Search",
    subtitle: "Manage search indexes",
    url: "/configuration/search",
    adminOnly: true,
    keywords: ["meilisearch", "index"],
  },
  {
    id: "config-audit",
    title: "Configuration > Audit Log",
    subtitle: "View system activity logs",
    url: "/configuration/audit",
    adminOnly: true,
    keywords: ["logs", "activity"],
  },
  {
    id: "config-logs",
    title: "Configuration > Application Logs",
    subtitle: "Real-time console log viewer",
    url: "/configuration/logs",
    adminOnly: true,
    keywords: ["console", "log viewer"],
  },
  {
    id: "config-log-retention",
    title: "Configuration > Log retention",
    subtitle: "Retention and cleanup config",
    url: "/configuration/log-retention",
    adminOnly: true,
    keywords: ["retention", "cleanup"],
  },
  {
    id: "config-jobs",
    title: "Configuration > Jobs",
    subtitle: "Monitor scheduled jobs",
    url: "/configuration/jobs",
    adminOnly: true,
    keywords: ["scheduled", "queue", "tasks"],
  },
  {
    id: "config-backup",
    title: "Configuration > Backup & Restore",
    subtitle: "Manage system backups",
    url: "/configuration/backup",
    adminOnly: true,
    keywords: ["restore", "export"],
  },
  {
    id: "config-usage",
    title: "Configuration > Usage & Costs",
    subtitle: "Integration usage analytics and cost tracking",
    url: "/configuration/usage",
    adminOnly: true,
    keywords: ["usage", "cost", "analytics", "tokens", "llm", "billing", "spending"],
  },
  {
    id: "config-notification-deliveries",
    title: "Configuration > Delivery Log",
    subtitle: "Notification delivery attempts and failures",
    url: "/configuration/notification-deliveries",
    adminOnly: true,
    keywords: ["notification", "delivery", "log", "failed", "retry", "channel", "rate limit"],
  },
  {
    id: "config-stripe",
    title: "Configuration > Stripe",
    subtitle: "Payment processing configuration",
    url: "/configuration/stripe",
    adminOnly: true,
    keywords: ["stripe", "payments", "connect", "billing", "webhooks"],
  },
  {
    id: "config-payments",
    title: "Configuration > Payment History",
    subtitle: "View payment transactions",
    url: "/configuration/payments",
    adminOnly: false,
    keywords: ["payments", "transactions", "history", "billing", "invoices"],
  },
  {
    id: "config-graphql",
    title: "Configuration > GraphQL API",
    subtitle: "GraphQL settings, API keys, and usage stats",
    url: "/configuration/graphql",
    adminOnly: true,
    keywords: ["graphql", "api", "keys", "rate limit", "introspection", "usage"],
  },
  // Email AI
  {
    id: "help-email-ai-features",
    title: "Help: AI Email Features",
    subtitle: "Help article",
    url: "help:email-ai-features",
    keywords: ["ai", "llm", "email", "summary", "labels", "priority", "smart replies"],
  },
  // Email Hosting
  {
    id: "mail",
    title: "Mail",
    subtitle: "Email inbox and client",
    url: "/mail",
    keywords: ["email", "inbox", "compose", "send", "receive"],
  },
  {
    id: "contacts",
    title: "Contacts",
    subtitle: "Manage email contacts",
    url: "/contacts",
    keywords: ["contacts", "address", "book", "people", "email", "autocomplete"],
  },
  {
    id: "config-email-provider",
    title: "Configuration > Email Provider",
    subtitle: "Provider API keys and settings",
    url: "/configuration/email-provider",
    adminOnly: true,
    keywords: ["mailgun", "email", "provider", "api", "webhook", "spam"],
  },
  {
    id: "config-email-domains",
    title: "Configuration > Email Domains",
    subtitle: "Manage email domains",
    url: "/configuration/email-domains",
    adminOnly: true,
    keywords: ["domain", "dns", "verify", "email"],
  },
  {
    id: "config-mailboxes",
    title: "Configuration > Mailboxes",
    subtitle: "Manage email addresses",
    url: "/configuration/mailboxes",
    adminOnly: true,
    keywords: ["mailbox", "address", "catchall", "email"],
  },
  {
    id: "config-spam-filter",
    title: "Configuration > Spam Filter",
    subtitle: "Allow and block sender lists",
    url: "/configuration/spam-filter",
    adminOnly: true,
    keywords: ["spam", "filter", "allow", "block", "sender", "domain"],
  },
  {
    id: "user-email-rules",
    title: "Email Rules",
    subtitle: "Automatic email processing rules",
    url: "/user/rules",
    adminOnly: false,
    keywords: ["email", "rules", "filters", "auto", "label", "archive", "forward"],
  },
];

/**
 * Search static navigation pages by query. Filters by admin permission and
 * matches against title, subtitle, and keywords. Returns at most MAX_PAGE_RESULTS.
 */
export function searchPages(query: string, isAdmin: boolean): SearchPage[] {
  const q = query.toLowerCase().trim();
  if (!q) return [];

  return SEARCH_PAGES.filter((page) => {
    if (page.adminOnly && !isAdmin) return false;

    const searchText = [
      page.title,
      page.subtitle,
      ...(page.keywords ?? []),
    ]
      .filter(Boolean)
      .join(" ")
      .toLowerCase();

    return searchText.includes(q);
  }).slice(0, MAX_PAGE_RESULTS);
}
