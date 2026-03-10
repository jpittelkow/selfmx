/**
 * Centralized app configuration for branding and display settings.
 * 
 * Logo/icon paths are null by default - the Logo component displays
 * text fallbacks. When logos are uploaded via the branding configuration
 * page, these paths will be populated.
 * 
 * Note: App name is always fetched from the database via `useAppConfig()` hook.
 * The backend ensures app_name always has a default value, so no hardcoded
 * fallback is needed in the frontend.
 */
export const APP_CONFIG = {
  /** Emoji used as logo/icon across the app (sidebar, tabs, PWA, etc.) */
  emoji: '📬',

  /** Short name for collapsed states (1-3 characters recommended) */
  shortName: process.env.NEXT_PUBLIC_APP_SHORT_NAME || 'MX',

  /** Full logo path (null = use text fallback) - use useAppConfig() for dynamic value */
  logo: null as string | null,

  /** Square icon path for collapsed sidebar (null = use text fallback) */
  icon: null as string | null,

  /** Favicon path */
  favicon: '/favicon.ico',
};

export type AppConfig = typeof APP_CONFIG;
