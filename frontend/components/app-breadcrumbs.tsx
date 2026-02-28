"use client";

import React from "react";
import { usePathname } from "next/navigation";
import Link from "next/link";
import {
  Breadcrumb,
  BreadcrumbItem,
  BreadcrumbLink,
  BreadcrumbList,
  BreadcrumbPage,
  BreadcrumbSeparator,
} from "@/components/ui/breadcrumb";

/** Maps route segments to human-readable labels. */
const SEGMENT_LABELS: Record<string, string> = {
  dashboard: "Dashboard",
  configuration: "Configuration",
  user: "Account",
  notifications: "Notifications",
  // Configuration sub-pages
  system: "System",
  branding: "Theme & Branding",
  backup: "Backup & Restore",
  changelog: "Changelog",
  users: "Users",
  groups: "Groups",
  security: "Security",
  sso: "SSO",
  api: "Webhooks",
  graphql: "GraphQL API",
  email: "Email",
  "email-templates": "Email Templates",
  "notification-templates": "Notification Templates",
  "notification-deliveries": "Delivery Log",
  ai: "AI / LLM",
  storage: "Storage",
  search: "Search",
  novu: "Novu",
  stripe: "Stripe",
  audit: "Audit Log",
  logs: "Application Logs",
  "access-logs": "Access Logs",
  "log-retention": "Log Retention",
  jobs: "Jobs",
  usage: "Usage & Costs",
  payments: "Payment History",
  profile: "Profile",
  preferences: "Preferences",
  files: "File Manager",
};

/** Routes where breadcrumbs should not be shown. */
const HIDDEN_ON = new Set(["/dashboard"]);

function labelForSegment(segment: string): string {
  return SEGMENT_LABELS[segment] ?? segment.replace(/-/g, " ").replace(/\b\w/g, (c) => c.toUpperCase());
}

export function AppBreadcrumbs() {
  const pathname = usePathname();

  if (!pathname || HIDDEN_ON.has(pathname)) {
    return null;
  }

  const segments = pathname.split("/").filter(Boolean);

  if (segments.length <= 1) {
    return null;
  }

  // Build breadcrumb items (skip the first segment if it's "dashboard" since
  // the sidebar already conveys that context)
  const crumbs: { label: string; href: string }[] = [];
  let accumulated = "";

  for (let i = 0; i < segments.length; i++) {
    accumulated += `/${segments[i]}`;
    crumbs.push({
      label: labelForSegment(segments[i]),
      href: accumulated,
    });
  }

  return (
    <Breadcrumb>
      <BreadcrumbList>
        {crumbs.map((crumb, index) => {
          const isLast = index === crumbs.length - 1;
          return (
            <React.Fragment key={crumb.href}>
              {index > 0 && <BreadcrumbSeparator />}
              <BreadcrumbItem>
                {isLast ? (
                  <BreadcrumbPage>{crumb.label}</BreadcrumbPage>
                ) : (
                  <BreadcrumbLink asChild>
                    <Link href={crumb.href}>{crumb.label}</Link>
                  </BreadcrumbLink>
                )}
              </BreadcrumbItem>
            </React.Fragment>
          );
        })}
      </BreadcrumbList>
    </Breadcrumb>
  );
}
