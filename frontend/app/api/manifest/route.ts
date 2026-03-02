import { NextResponse } from 'next/server';
import { headers } from 'next/headers';

/**
 * Dynamic manifest.json endpoint that uses branding settings from the backend.
 * This allows PWA icons to use uploaded favicon/logo instead of static files.
 * 
 * Route is at /api/manifest - Next.js API routes take precedence over rewrites.
 * 
 * Server-side fetch() requires absolute URLs. We reconstruct the origin from
 * the incoming request headers, falling back to INTERNAL_API_URL (defaults to
 * http://127.0.0.1:80 in the single-container Docker setup).
 */

function getApiBase(h: Headers): string {
  // 1. Try NEXT_PUBLIC_API_URL (set at build time or runtime)
  const publicUrl = process.env.NEXT_PUBLIC_API_URL;
  if (publicUrl) return `${publicUrl}/api`;

  // 2. Reconstruct from request headers (works in both dev and prod)
  const host = h.get('host');
  if (host) {
    const proto = (h.get('x-forwarded-proto') || 'http').split(',')[0].trim();
    return `${proto}://${host}/api`;
  }

  // 3. Fallback: runtime env var for internal container communication
  const internalUrl = process.env.INTERNAL_API_URL || 'http://127.0.0.1:80';
  return `${internalUrl}/api`;
}

export async function GET() {
  try {
    const h = await headers();
    const apiPath = getApiBase(h);

    // Fetch branding settings from backend
    const brandingResponse = await fetch(`${apiPath}/branding`, {
      cache: 'no-store', // Always fetch fresh branding settings
    });

    let faviconUrl: string | null = null;
    let appName = 'selfmx';
    let shortName = 'MX';
    let themeColor = '#3b82f6';
    const backgroundColor = '#ffffff'; // Always white for PWA splash screen

    if (brandingResponse.ok) {
      const branding = await brandingResponse.json();
      faviconUrl = branding.settings?.favicon_url || null;
      themeColor = branding.settings?.primary_color || themeColor;
    }

    // Fetch system settings for app name
    const systemSettingsResponse = await fetch(`${apiPath}/system-settings/public`, {
      cache: 'no-store',
    });

    if (systemSettingsResponse.ok) {
      const systemSettings = await systemSettingsResponse.json();
      appName = systemSettings.settings?.general?.app_name || appName;
      // Generate short name from app name (first 2-3 characters)
      shortName = appName.length > 3 ? appName.substring(0, 3).toUpperCase() : appName.toUpperCase();
    }

    // Build icons array - use uploaded favicon if available, otherwise full icon set from /icons/
    // Split purpose into separate entries so browsers can choose the right variant:
    //   "any" = standard display; "maskable" = adaptive icon (Android clips outer ~10%)
    const iconSizes = [48, 72, 96, 128, 144, 152, 192, 384, 512];
    const icons = faviconUrl
      ? [
          { src: faviconUrl, sizes: '192x192', type: 'image/png', purpose: 'any' as const },
          { src: faviconUrl, sizes: '192x192', type: 'image/png', purpose: 'maskable' as const },
          { src: faviconUrl, sizes: '512x512', type: 'image/png', purpose: 'any' as const },
          { src: faviconUrl, sizes: '512x512', type: 'image/png', purpose: 'maskable' as const },
        ]
      : [
          ...iconSizes.flatMap((s) => {
            const base = { src: `/icons/icon-${s}.png`, sizes: `${s}x${s}`, type: 'image/png' as const };
            if (s === 192 || s === 512) {
              return [
                { ...base, purpose: 'any' as const },
                { ...base, purpose: 'maskable' as const },
              ];
            }
            return [base];
          }),
        ];

    const manifest = {
      name: appName,
      short_name: shortName,
      description: 'Self-hosted Email the easy way',
      icons,
      theme_color: themeColor,
      background_color: backgroundColor,
      display: 'standalone',
      start_url: '/',
      orientation: 'portrait-primary',
      categories: ['productivity', 'utilities'],
      shortcuts: [
        { name: 'Inbox', short_name: 'Inbox', url: '/mail', icons: [{ src: '/icons/shortcut-dashboard.png', sizes: '96x96', type: 'image/png' }] },
        { name: 'Settings', short_name: 'Settings', url: '/user/preferences', icons: [{ src: '/icons/shortcut-settings.png', sizes: '96x96', type: 'image/png' }] },
      ],
      share_target: {
        action: '/share',
        method: 'GET',
        params: { title: 'title', text: 'text', url: 'url' },
      },
    };

    return NextResponse.json(manifest, {
      headers: {
        'Content-Type': 'application/manifest+json',
        'Cache-Control': 'public, max-age=3600, stale-while-revalidate=86400',
      },
    });
  } catch (error) {
    // Fallback to static manifest if backend is unavailable
    const fallbackManifest = {
      name: 'selfmx',
      short_name: 'MX',
      description: 'Self-hosted Email the easy way',
      icons: [
        { src: '/icons/icon-192.png', sizes: '192x192', type: 'image/png', purpose: 'any' },
        { src: '/icons/icon-192.png', sizes: '192x192', type: 'image/png', purpose: 'maskable' },
        { src: '/icons/icon-512.png', sizes: '512x512', type: 'image/png', purpose: 'any' },
        { src: '/icons/icon-512.png', sizes: '512x512', type: 'image/png', purpose: 'maskable' },
      ],
      theme_color: '#3b82f6',
      background_color: '#ffffff',
      display: 'standalone',
      start_url: '/',
      orientation: 'portrait-primary',
      categories: ['productivity', 'utilities'],
      shortcuts: [
        { name: 'Inbox', short_name: 'Inbox', url: '/mail', icons: [{ src: '/icons/shortcut-dashboard.png', sizes: '96x96', type: 'image/png' }] },
        { name: 'Settings', short_name: 'Settings', url: '/user/preferences', icons: [{ src: '/icons/shortcut-settings.png', sizes: '96x96', type: 'image/png' }] },
      ],
      share_target: {
        action: '/share',
        method: 'GET',
        params: { title: 'title', text: 'text', url: 'url' },
      },
    };

    return NextResponse.json(fallbackManifest, {
      headers: {
        'Content-Type': 'application/manifest+json',
        'Cache-Control': 'public, max-age=3600',
      },
    });
  }
}
