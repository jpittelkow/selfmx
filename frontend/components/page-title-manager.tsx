"use client";

import { usePathname } from "next/navigation";
import { usePageTitle } from "@/lib/use-page-title";
import { useMailData } from "@/lib/mail-data-provider";

/**
 * Route-to-title mapping for automatic page title detection.
 * Maps pathnames to their display titles.
 */
const routeTitles: Record<string, string> = {
  // Landing & Auth
  "/": "Welcome",
  "/login": "Sign In",
  "/register": "Create Account",
  "/forgot-password": "Forgot Password",
  "/reset-password": "Reset Password",
  "/verify-email": "Verify Email",

  // Mail — title managed by MailPage with view-specific labels

  // Notifications
  "/notifications": "Notifications",

  // Share
  "/share": "Shared Content",

  // Configuration
  "/configuration": "Configuration",
  "/configuration/system": "System Settings",
  "/configuration/branding": "Theme & Branding",
  "/configuration/notifications": "Notifications",
  "/configuration/ai": "AI Settings",
  "/configuration/backup": "Backup & Restore",
  "/configuration/email": "Email Settings",
  "/configuration/email-templates": "Email Templates",
  "/configuration/storage": "Storage",
  "/configuration/storage/files": "File Browser",
  "/configuration/api": "API Keys",
  "/configuration/jobs": "Background Jobs",
  "/configuration/audit": "Audit Log",
  "/configuration/logs": "Application Logs",
  "/configuration/log-retention": "Log Retention",
  "/configuration/users": "User Management",
  "/configuration/groups": "User Groups",
  "/configuration/security": "Security",
  "/configuration/sso": "Single Sign-On",
  "/configuration/search": "Search Settings",
  "/configuration/notification-templates": "Notification Templates",
  "/configuration/email-provider": "Email Provider",
  "/configuration/email-domains": "Email Domains",
  "/configuration/mailboxes": "Mailboxes",
  "/configuration/changelog": "Changelog",
  "/configuration/graphql": "GraphQL API",
  "/configuration/payments": "Payment History",
  "/configuration/usage": "Usage & Costs",
  "/configuration/stripe": "Stripe",
  "/configuration/novu": "Novu",
  "/configuration/spam-filter": "Spam Filter",
  "/configuration/notification-deliveries": "Delivery Log",
  "/configuration/profile": "Profile",

  // Email client
  "/contacts": "Contacts",

  // User pages
  "/user/profile": "Profile",
  "/user/security": "Security",
  "/user/preferences": "Preferences",
  "/user/rules": "Email Rules",

  // Mail Settings
  "/mail/settings": "Mail Settings",
  "/mail/settings/rules": "Email Rules",
  "/mail/settings/spam": "Spam Filter",
  "/mail/settings/import": "Import Emails",

  // Admin pages
  "/admin": "Admin",
  "/admin/users": "User Management",
  "/admin/audit": "Audit Log",
  "/admin/jobs": "Background Jobs",
  "/admin/backup": "Backup & Restore",

  // Settings (duplicate structure)
  "/settings": "Settings",
  "/settings/profile": "Profile",
  "/settings/branding": "Theme & Branding",
  "/settings/email": "Email Settings",
  "/settings/ai": "AI Settings",
  "/settings/api": "API Keys",
  "/settings/storage": "Storage",
  "/settings/notifications": "Notifications",
  "/settings/system": "System Settings",
  "/settings/security": "Security",
};

/**
 * Fallback title matching for dynamic routes with [id]/[key] segments.
 */
function getDynamicRouteTitle(pathname: string): string | undefined {
  if (pathname.startsWith("/configuration/email-templates/")) return "Edit Template";
  if (pathname.startsWith("/configuration/notification-templates/")) return "Edit Notification Template";
  if (pathname.startsWith("/configuration/email-domains/")) return "Domain Details";
  return undefined;
}

/**
 * PageTitleManager automatically sets page titles based on the current route.
 * Includes unread email count in the browser tab for all dashboard pages.
 * This component should be placed in the AppShell to apply globally.
 */
export function PageTitleManager() {
  const pathname = usePathname();
  const { unreadCount } = useMailData();

  const pageTitle = routeTitles[pathname] ?? getDynamicRouteTitle(pathname);

  // Only active when we have a matched title, so pages that manage their
  // own title (e.g. mail page) don't get competing title updates.
  const hasTitle = !!pageTitle;
  usePageTitle(pageTitle, undefined, hasTitle ? unreadCount : undefined, hasTitle);

  return null;
}
