# PWA Phase 4 and 5: Install Experience and Advanced Features - 2026-01-31

## Overview

Implemented Phase 4 (Install Experience) and Phase 5 (Advanced Features) of the PWA roadmap: enhanced manifest with full icon set, screenshots, shortcuts, and Share Target; install prompt with visit tracking and 30-day dismissal; Install App option in User Preferences; and Share Target page for receiving shared content.

## Implementation Approach

### Phase 4: Install Experience

- **Manifest** (`frontend/public/manifest.json`): Full icon set (48–512px) under `/icons/`, screenshots and shortcuts entries, categories, Share Target (GET with title, text, url).
- **Icon generation** (`scripts/generate-pwa-icons.mjs`): Generates all required sizes into `frontend/public/icons/`, plus shortcut icons and backward-compatible `icon-192.png`/`icon-512.png` in `public/`.
- **useInstallPrompt** (`frontend/lib/use-install-prompt.ts`): Captures `beforeinstallprompt`, tracks visit count and dismissal in localStorage (30-day re-prompt), exposes `promptInstall()`, `dismissBanner(dontShowAgain)`, `shouldShowBanner`, `canPrompt`, `isInstalled`.
- **InstallPrompt** (`frontend/components/install-prompt.tsx`): Non-intrusive bottom banner with Install / Not now, "Don't show again" checkbox, respects `prefers-reduced-motion`.
- **App shell**: InstallPrompt rendered in AppShell so the banner appears on dashboard after 2+ visits when install is available.
- **User Preferences**: New "Install App" card; shows Install button when `canPrompt && !isInstalled`, "App is installed" when standalone, fallback copy for unsupported browsers.

### Phase 5: Advanced Features

- **Background Sync**: Already present in `frontend/public/sw.js` (sync event, `processQueueInSW`, tag `retry-failed-requests`); no code change.
- **Share Target**: Manifest `share_target` with action `/share`, method GET, params title/text/url. Share page (`frontend/app/share/page.tsx`) reads search params and displays shared content with links to dashboard or login.

## Challenges Encountered

- **beforeinstallprompt**: Not in TypeScript DOM lib; defined `BeforeInstallPromptEvent` interface in the hook.
- **Visit count**: Incremented on every mount of the hook; acceptable for "2+ visits" (first load = 1, second = 2). Banner only shows when `shouldShowBanner` (visitCount >= 2, not dismissed, canPrompt, not installed).
- **Share Target method**: Using GET so the share page can read `useSearchParams()`; POST would require an API route or form handling.

## Observations

- Install banner is conservative: show only when install is available, after 2 visits, and not dismissed (or 30 days passed).
- Screenshots directory created with `.gitkeep`; actual screenshot assets can be added later for install UI.
- Share Target makes the app appear in the system share sheet on supported platforms (e.g. Android).

## Trade-offs

- Screenshots are placeholders (paths in manifest); real dashboard/mobile screenshots improve install UI.
- Periodic Background Sync and protocol handlers were not implemented (Chrome-only / lower priority).
- Share Target GET passes shared data in URL; very long text may be truncated by the browser.

## Next Steps (Future Considerations)

- Add real screenshot images for install prompts.
- Optional: Periodic Background Sync for cached data refresh (Chrome).
- Optional: Protocol handler (`web+selfmx://`) for deep links.

## Testing Notes

- Run `node scripts/generate-pwa-icons.mjs` to ensure all icons exist (requires `sharp` in frontend).
- Test install banner: Chrome/Edge, 2+ visits, not installed; dismiss with and without "Don't show again."
- Test Install App in User Preferences when install is available vs when already installed.
- Test Share Target: install PWA, share a link from another app and choose selfmx; confirm /share shows title/text/url.
