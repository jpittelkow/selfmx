"use client";

import { useEffect } from "react";
import { usePathname } from "next/navigation";
import { useAppConfig } from "@/lib/app-config";
import { errorLogger } from "@/lib/error-logger";
import { APP_CONFIG } from "@/config/app";

/**
 * Hook to set page title and meta tags dynamically.
 * 
 * @param pageTitle - Optional page-specific title (e.g., "Dashboard")
 * @param description - Optional meta description
 * @param unreadCount - Optional unread email count to prepend as "(N) "
 * @param enabled - Whether to apply the title (default true). Set to false to skip.
 *
 * @example
 * // Just app name
 * usePageTitle();
 *
 * // Page name + app name
 * usePageTitle('Dashboard');
 *
 * // With unread count: "(3) Inbox | AppName"
 * usePageTitle('Inbox', undefined, 3);
 */
export function usePageTitle(pageTitle?: string, description?: string, unreadCount?: number, enabled = true) {
  const { appName, isLoading } = useAppConfig();
  const pathname = usePathname();

  useEffect(() => {
    // Skip when disabled or when appName hasn't loaded yet
    if (!enabled || isLoading || !appName) return;

    // Calculate the full title once
    const unreadPrefix = (unreadCount && unreadCount > 0) ? `(${unreadCount}) ` : "";
    const emoji = APP_CONFIG.emoji;
    const fullTitle = (pageTitle && pageTitle.trim()) ? `${unreadPrefix}${emoji} ${pageTitle} | ${appName}` : `${unreadPrefix}${emoji} ${appName}`;

    const updateTitle = () => {
      try {
        document.title = fullTitle;
      } catch (e) {
        errorLogger.captureMessage(
          "Failed to update document.title",
          "warning",
          { error: e instanceof Error ? e.message : String(e) }
        );
      }

      // Update meta description if provided
      if (description) {
        let metaDescription = document.querySelector('meta[name="description"]');
        if (!metaDescription) {
          metaDescription = document.createElement('meta');
          metaDescription.setAttribute('name', 'description');
          document.head.appendChild(metaDescription);
        }
        metaDescription.setAttribute('content', description);
      }

      // Update Open Graph title
      let ogTitle = document.querySelector('meta[property="og:title"]');
      if (!ogTitle) {
        ogTitle = document.createElement('meta');
        ogTitle.setAttribute('property', 'og:title');
        document.head.appendChild(ogTitle);
      }
      ogTitle.setAttribute('content', fullTitle);
    };

    // Update immediately to minimize flash
    updateTitle();

    // Post-render update to ensure title sticks after any framework hydration
    const timeoutId = setTimeout(updateTitle, 0);

    return () => {
      clearTimeout(timeoutId);
    };
  }, [appName, pageTitle, description, pathname, isLoading, unreadCount, enabled]);
}
