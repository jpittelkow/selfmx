import type { Metadata, Viewport } from "next";
import "./globals.css";
import { Providers } from "@/components/providers";
import { bodyFont, headingFont } from "@/config/fonts";

// Viewport config - themeColor styles the mobile address bar / status bar.
// Dynamic branding overrides this client-side via AppConfigProvider.
export const viewport: Viewport = {
  themeColor: "#3b82f6",
};

// Metadata uses minimal title for SSR - actual app name from settings
// will be used client-side via usePageTitle hook in components
// Using empty string to avoid flash of default name before client-side update
export const metadata: Metadata = {
  title: "",
  description: "Self-hosted Email the easy way",
  icons: {
    icon: [
      { url: "/favicon.svg", type: "image/svg+xml" },
      { url: "/favicon.ico", sizes: "any" },
    ],
    apple: [
      { url: "/apple-icon.png", sizes: "180x180", type: "image/png" },
    ],
  },
  manifest: "/api/manifest",
};

export default function RootLayout({
  children,
}: Readonly<{
  children: React.ReactNode;
}>) {
  // IMPORTANT: localStorage keys here MUST match:
  //   - ThemeProvider's default storageKey ("selfmx-theme")
  //   - ThemePicker's COLOR_THEME_KEY ("selfmx-color-theme") for per-user override
  // Global color theme is applied by AppConfigProvider; user override takes priority.
  const themeScript = `
(function() {
  var key = 'selfmx-theme';
  var stored = localStorage.getItem(key);
  var resolved;
  if (stored === 'light' || stored === 'dark') {
    resolved = stored;
  } else {
    resolved = window.matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light';
  }
  document.documentElement.classList.add(resolved);
  var colorTheme = localStorage.getItem('selfmx-color-theme')
    || localStorage.getItem('selfmx-global-color-theme')
    || 'default';
  document.documentElement.setAttribute('data-theme', colorTheme);
})();
  `.trim();

  return (
    <html lang="en" suppressHydrationWarning>
      <body className={`${bodyFont.variable} ${headingFont.variable} ${bodyFont.className}`}>
        <script
          dangerouslySetInnerHTML={{ __html: themeScript }}
        />
        <Providers>
          {children}
        </Providers>
      </body>
    </html>
  );
}
