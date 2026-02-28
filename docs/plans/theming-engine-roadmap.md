# Theming Engine Roadmap

Build a full theming engine with pre-set themes, custom theme support, and instructions for adding or modifying themes for both dark and light mode.

## Overview

Sourdough currently supports light/dark/system mode via CSS custom properties in `globals.css` and a custom `ThemeProvider`. This roadmap extends that foundation into a full theming engine with multiple pre-set color themes, per-user theme persistence, and clear patterns for creating new themes.

## Current State

- [x] Light/dark/system toggle via `ThemeProvider` (`frontend/components/theme-provider.tsx`)
- [x] CSS custom properties in `globals.css` (`:root` for light, `.dark` for dark)
- [x] Theme toggle component (`frontend/components/theme-toggle.tsx`)
- [x] Theme onboarding step (`frontend/components/onboarding/steps/theme-step.tsx`)
- [x] User preference persistence (localStorage + API sync)
- [x] Default theme extracted to `frontend/styles/themes/default.css`
- [x] Theme registry in `frontend/lib/themes.ts`
- [x] `data-theme` attribute on `<html>` managed by ThemeProvider
- [x] Expanded variable surface: colors, radius, font sizes, font weights, spacing, layout sizing
- [x] Recipe and pattern documentation for adding new themes
- [ ] No way to switch between color themes (Phase 2: picker UI)
- [ ] No admin-configurable default theme (Phase 3: backend)
- [ ] No theme preview or theme builder (Phase 4: optional)

## Architecture

### Design Principles

1. **CSS custom properties only** — Themes are defined as sets of HSL values applied to existing CSS variables. No component changes needed per theme.
2. **Light + dark variant per theme** — Every color theme must define both `:root` (light) and `.dark` (dark) variable sets.
3. **Theme = color palette, Mode = light/dark** — These are independent axes. A user picks a *theme* (e.g., "Ocean") and a *mode* (light/dark/system).
4. **Zero JS bundle impact** — Theme definitions are pure CSS. Only the theme switcher adds JS.
5. **shadcn/ui compatible** — All themes use the same CSS variable names that shadcn/ui components consume.

### How It Works

```
User picks theme ("Ocean") + mode ("dark")
  → ThemeProvider sets data-theme="ocean" + class="dark" on <html>
  → CSS matches [data-theme="ocean"].dark { ... }
  → All shadcn/ui components automatically use the new colors
```

## Phase 1: Theme Registry & CSS Infrastructure ✅ COMPLETE

Define the theme format, ship the Default theme, and create recipes/patterns for adding new themes.

### Tasks

- [x] Create `frontend/lib/themes.ts` — theme registry with metadata (id, name, description, preview colors)
- [x] Create `frontend/styles/themes/` directory for theme CSS files
- [x] Define base theme format: each theme is a CSS file with `[data-theme="<id>"]` and `[data-theme="<id>"].dark` selectors
- [x] Move current `globals.css` color variables into `themes/default.css` as the "Default" theme
- [x] Import all theme CSS files in `globals.css` (or a `themes.css` barrel file)
- [x] Update `ThemeProvider` to manage `data-theme` attribute alongside the existing `dark`/`light` class
- [x] Expand CSS variable surface: font sizes, font weights, spacing scale, layout sizing, radius scale
- [x] Wire new variables into `tailwind.config.ts`
- [x] Update `layout.tsx` SSR script for `data-theme` FOUC prevention
- [x] Create recipe: `docs/ai/recipes/add-theme.md`
- [x] Create pattern: `docs/ai/patterns/theming.md`

### Pre-Set Themes to Ship

Each theme defines both light and dark mode variants:

| Theme ID | Name | Description | Light Primary | Dark Primary |
|----------|------|-------------|---------------|--------------|
| `default` | Default | Clean neutral slate (current) | Slate blue | Slate blue |
| `ocean` | Ocean | Cool blues and teals | Blue 600 | Blue 400 |
| `forest` | Forest | Earthy greens | Green 700 | Green 400 |
| `sunset` | Sunset | Warm oranges and ambers | Orange 600 | Amber 400 |
| `rose` | Rose | Soft pinks and reds | Rose 600 | Rose 400 |
| `lavender` | Lavender | Purple tones | Violet 600 | Violet 400 |
| `midnight` | Midnight | High-contrast dark-first | Indigo 700 | Indigo 300 |
| `mono` | Mono | Pure grayscale, no color accent | Neutral 900 | Neutral 100 |

### Files

| File | Purpose |
|------|---------|
| `frontend/lib/themes.ts` | Theme registry: IDs, names, preview colors |
| `frontend/styles/themes/*.css` | One CSS file per theme |
| `frontend/app/globals.css` | Imports theme CSS files |
| `frontend/components/theme-provider.tsx` | Add `data-theme` attribute management |

## Phase 2: Theme Picker UI

Add a theme selector to User Preferences and the onboarding wizard.

### Tasks

- [ ] Create `frontend/components/theme-picker.tsx` — grid of theme swatches with live preview
- [ ] Each swatch shows a mini color preview (primary, secondary, accent, background)
- [ ] Clicking a swatch applies the theme immediately (optimistic)
- [ ] Update User Preferences page (`frontend/app/(dashboard)/user/preferences/page.tsx`) to include the theme picker in the Appearance section
- [ ] Update onboarding `ThemeStep` to show color themes alongside light/dark/system choice
- [ ] Update `ThemeToggle` component to optionally show current theme name
- [ ] Persist selected theme to localStorage (`sourdough-color-theme`) and sync to user settings API

### Files

| File | Purpose |
|------|---------|
| `frontend/components/theme-picker.tsx` | Theme selection grid component |
| `frontend/app/(dashboard)/user/preferences/page.tsx` | Add theme picker to Appearance card |
| `frontend/components/onboarding/steps/theme-step.tsx` | Add theme swatches |
| `frontend/components/theme-toggle.tsx` | Optional theme name display |
| `frontend/components/theme-provider.tsx` | Persist color theme to localStorage |

## Phase 3: Backend Persistence & Admin Default

Store theme preference server-side and let admins set a default theme.

### Tasks

- [ ] Add `color_theme` field to user settings API (`PUT /api/user/settings`)
- [ ] Update `UserSettingController` to accept and return `color_theme`
- [ ] Add `default_theme` to settings schema (`backend/config/settings-schema.php`) under Appearance group
- [ ] Create or update admin Appearance settings UI to set default theme for new users
- [ ] On login, load user's `color_theme` preference from API (fall back to admin default, then `default`)
- [ ] Register new setting in search pages (backend + frontend)

### Files

| File | Purpose |
|------|---------|
| `backend/app/Http/Controllers/Api/UserSettingController.php` | Accept `color_theme` |
| `backend/config/settings-schema.php` | `default_theme` system setting |
| `frontend/lib/search-pages.ts` | Register appearance settings |
| `backend/config/search-pages.php` | Register appearance settings |

## Phase 4: Custom Theme Builder (Optional)

Let advanced users create their own themes via a UI.

### Tasks

- [ ] Create a theme builder page at `/user/preferences/theme-builder` or a modal
- [ ] Provide color pickers for each CSS variable (primary, secondary, accent, muted, destructive, background, foreground, border, etc.)
- [ ] Live preview panel showing how components look with chosen colors
- [ ] "Start from" dropdown to base a custom theme on an existing pre-set
- [ ] Save custom theme to user settings (stored as JSON blob)
- [ ] Apply custom theme via inline `<style>` tag with CSS variables
- [ ] Export/import theme as JSON for sharing

---

## How to Add a New Pre-Set Theme

See the full recipe: [docs/ai/recipes/add-theme.md](../../ai/recipes/add-theme.md)

See the theming pattern: [docs/ai/patterns/theming.md](../../ai/patterns/theming.md)

### Quick Summary

1. Create `frontend/styles/themes/<theme-id>.css` with `[data-theme="<id>"]` and `[data-theme="<id>"].dark` selectors
2. Register in `frontend/lib/themes.ts` with metadata and preview colors
3. Add `@import` in `frontend/app/globals.css`
4. Test both light and dark modes across all pages

### Theme Variable Groups

Each theme can control **6 categories** of CSS custom properties (45+ variables total):

| Category | Variables | Example |
|----------|-----------|---------|
| **Colors** (19) | `--background`, `--foreground`, `--primary`, `--secondary`, `--muted`, `--accent`, `--destructive`, `--border`, `--input`, `--ring`, + foreground variants | `222.2 84% 4.9%` |
| **Radius** (5) | `--radius`, `--radius-sm`, `--radius-lg`, `--radius-xl`, `--radius-full` | `0.5rem` |
| **Font Sizes** (7) | `--text-xs` through `--text-3xl` | `1rem` |
| **Font Weights** (4) | `--font-weight-normal`, `-medium`, `-semibold`, `-bold` | `400` |
| **Spacing** (6) | `--spacing-xs` through `--spacing-2xl` | `1rem` |
| **Layout Sizing** (4) | `--sidebar-width`, `--header-height`, `--container-max-width`, `--card-padding` | `16rem` |

### Useful Tools

- [HSL Color Picker](https://hslpicker.com/) — Convert between hex and HSL
- [Realtime Colors](https://www.realtimecolors.com/) — Preview color systems on realistic UI
- [Contrast Checker](https://webaim.org/resources/contrastchecker/) — Verify WCAG compliance
- [shadcn/ui Themes](https://ui.shadcn.com/themes) — Reference themes in the same CSS variable format
