"use client";

import { usePathname } from "next/navigation";
import { usePageTitle } from "@/lib/use-page-title";

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

  // Mail
  "/mail": "Inbox",

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

  // Email client
  "/contacts": "Contacts",

  // User pages
  "/user/profile": "Profile",
  "/user/security": "Security",
  "/user/preferences": "Preferences",
  "/user/rules": "Email Rules",

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
 * PageTitleManager automatically sets page titles based on the current route.
 * This component should be placed in the AppShell to apply globally.
 */
export function PageTitleManager() {
  const pathname = usePathname();
  const pageTitle = routeTitles[pathname];

  usePageTitle(pageTitle);

  return null; // This component renders nothing
}
