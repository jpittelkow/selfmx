# Theming Patterns

Architecture and patterns for the Sourdough theming system.

## Architecture Overview

```
User picks theme ("Ocean") + mode ("dark")
  → ThemeProvider sets data-theme="ocean" + class="dark" on <html>
  → CSS matches [data-theme="ocean"].dark { --primary: ...; }
  → Tailwind reads hsl(var(--primary)) at build time
  → All shadcn/ui components automatically use the new colors
```

### Key Files

| File | Role |
|------|------|
| `frontend/styles/themes/*.css` | Theme CSS files (one per theme) |
| `frontend/lib/themes.ts` | Theme registry (IDs, names, preview colors) |
| `frontend/components/theme-provider.tsx` | React context for theme + color theme state |
| `frontend/app/globals.css` | Imports theme CSS, defines fallback variables |
| `frontend/app/layout.tsx` | SSR script for FOUC prevention |
| `frontend/tailwind.config.ts` | Wires CSS variables into Tailwind utilities |
| `frontend/config/fonts.ts` | Font loader (Next.js Google Fonts) |
| `frontend/lib/theme-colors.ts` | Runtime branding color override utility |

## Theme vs Mode

These are **independent axes**:

- **Theme** = color palette + typography + spacing (e.g., "Default", "Ocean", "Forest")
- **Mode** = light or dark appearance

A user selects both. The CSS selector `[data-theme="ocean"].dark` applies the Ocean theme in dark mode.

```
Theme: Ocean  + Mode: light  → [data-theme="ocean"]:not(.dark)
Theme: Ocean  + Mode: dark   → [data-theme="ocean"].dark
Theme: Default + Mode: dark  → [data-theme="default"].dark
```

**Important**: Light mode selectors use `:not(.dark)` instead of bare `[data-theme]` for CSS specificity. Without it, the `:root` fallback in globals.css overrides theme light colors because Next.js/Turbopack may split theme CSS into separate chunks that load before the globals bundle.

### Storage

| Axis | localStorage Key | Default |
|------|-----------------|---------|
| Mode | `sourdough-theme` | `"system"` |
| Theme | `sourdough-color-theme` | `"default"` |

## Variable Categories

Themes control six categories of CSS custom properties:

### 1. Colors (19 variables)

HSL space-separated format: `222.2 84% 4.9%` (no `hsl()` wrapper).

Tailwind reads these as `hsl(var(--primary))` via `tailwind.config.ts`.

Both light and dark mode variants are required for each theme.

### 2. Radius (5 variables)

`--radius`, `--radius-sm`, `--radius-lg`, `--radius-xl`, `--radius-full`

Controls border-radius across all components. A "sharp" theme might use `0` for radius, a "soft" theme might use `1rem`.

### 3. Font Sizes (7 variables)

`--text-xs` through `--text-3xl`

Override Tailwind's default type scale. A "compact" theme could decrease sizes; a "large-print" theme could increase them.

### 4. Font Weights (4 variables)

`--font-weight-normal`, `--font-weight-medium`, `--font-weight-semibold`, `--font-weight-bold`

### 5. Spacing (6 variables)

`--spacing-xs` through `--spacing-2xl`

Available in Tailwind as `p-theme-sm`, `m-theme-lg`, `gap-theme-md`, etc.

### 6. Layout Sizing (4 variables)

`--sidebar-width`, `--header-height`, `--container-max-width`, `--card-padding`

Controls structural dimensions of the app shell.

## How Fonts Work

Fonts flow through three layers:

1. **Next.js font loader** (`frontend/config/fonts.ts`) — Downloads Google Fonts and creates CSS variables `--font-body` and `--font-heading`
2. **Tailwind config** — Maps `font-sans` → `var(--font-body)` and `font-heading` → `var(--font-heading)`
3. **globals.css** — Applies `--font-heading` to `h1`-`h6` elements

The font loader sets variables via className on `<body>`. Themes can reference different font families by overriding these variables, but the actual font files must be loaded by Next.js first.

To change fonts project-wide, edit `frontend/config/fonts.ts`. See the comment in that file for popular pairings.

## How Branding Colors Interact with Themes

The admin branding page (`/configuration/branding`) lets admins set a primary and secondary color. These are applied at runtime via `applyThemeColors()` in `frontend/lib/theme-colors.ts`, which uses `document.documentElement.style.setProperty()`.

Inline styles have higher CSS specificity than `[data-theme]` selectors, so **branding colors override theme colors** when set. This is intentional — an admin's brand takes priority over a user's theme choice for primary/secondary.

## FOUC Prevention

The SSR script in `layout.tsx` runs before React hydration to prevent flash of unstyled content:

```js
// Reads from localStorage, applies class and data-theme immediately
var resolved = /* light or dark */;
document.documentElement.classList.add(resolved);
var colorTheme = localStorage.getItem('sourdough-color-theme') || 'default';
document.documentElement.setAttribute('data-theme', colorTheme);
```

This ensures the correct theme is visible on first paint, before React mounts.

## Adding a New Theme

See the recipe: [add-theme.md](../recipes/add-theme.md)

## Common Patterns

### Reading the current theme in a component

```tsx
import { useTheme } from "@/components/theme-provider";

function MyComponent() {
  const { colorTheme, setColorTheme, theme, resolvedTheme } = useTheme();
  // colorTheme: "default", "ocean", etc.
  // theme: "light", "dark", "system"
  // resolvedTheme: "light" or "dark" (resolved from system)
}
```

### Switching themes programmatically

```tsx
const { setColorTheme } = useTheme();
setColorTheme("ocean"); // Persists to localStorage + applies data-theme
```

### Using theme-aware spacing in Tailwind

```tsx
<div className="p-theme-md gap-theme-sm">
  {/* Padding and gap controlled by theme variables */}
</div>
```

### Testing a theme in DevTools

```js
// Switch color theme without React
document.documentElement.setAttribute('data-theme', 'ocean');

// Toggle dark mode
document.documentElement.classList.toggle('dark');
document.documentElement.classList.toggle('light');
```
