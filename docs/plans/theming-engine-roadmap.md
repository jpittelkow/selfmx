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
- [ ] Only one color palette (shadcn/ui default slate)
- [ ] No way to switch between color themes
- [ ] No admin-configurable default theme
- [ ] No theme preview or theme builder

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

## Phase 1: Theme Registry & CSS Infrastructure

Define the theme format and ship pre-set themes.

### Tasks

- [ ] Create `frontend/lib/themes.ts` — theme registry with metadata (id, name, description, preview colors)
- [ ] Create `frontend/styles/themes/` directory for theme CSS files
- [ ] Define base theme format: each theme is a CSS file with `[data-theme="<id>"]` and `[data-theme="<id>"].dark` selectors
- [ ] Move current `globals.css` color variables into `themes/default.css` as the "Default" theme
- [ ] Import all theme CSS files in `globals.css` (or a `themes.css` barrel file)
- [ ] Update `ThemeProvider` to manage `data-theme` attribute alongside the existing `dark`/`light` class

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

Follow these steps to add a new color theme:

### 1. Create the CSS file

Create `frontend/styles/themes/<theme-id>.css`:

```css
/* Theme: <Theme Name> */
/* Description: <Short description> */

[data-theme="<theme-id>"] {
  --background: <h> <s>% <l>%;
  --foreground: <h> <s>% <l>%;
  --card: <h> <s>% <l>%;
  --card-foreground: <h> <s>% <l>%;
  --popover: <h> <s>% <l>%;
  --popover-foreground: <h> <s>% <l>%;
  --primary: <h> <s>% <l>%;
  --primary-foreground: <h> <s>% <l>%;
  --secondary: <h> <s>% <l>%;
  --secondary-foreground: <h> <s>% <l>%;
  --muted: <h> <s>% <l>%;
  --muted-foreground: <h> <s>% <l>%;
  --accent: <h> <s>% <l>%;
  --accent-foreground: <h> <s>% <l>%;
  --destructive: <h> <s>% <l>%;
  --destructive-foreground: <h> <s>% <l>%;
  --border: <h> <s>% <l>%;
  --input: <h> <s>% <l>%;
  --ring: <h> <s>% <l>%;
  --radius: 0.5rem;
}

[data-theme="<theme-id>"].dark {
  --background: <h> <s>% <l>%;
  --foreground: <h> <s>% <l>%;
  --card: <h> <s>% <l>%;
  --card-foreground: <h> <s>% <l>%;
  --popover: <h> <s>% <l>%;
  --popover-foreground: <h> <s>% <l>%;
  --primary: <h> <s>% <l>%;
  --primary-foreground: <h> <s>% <l>%;
  --secondary: <h> <s>% <l>%;
  --secondary-foreground: <h> <s>% <l>%;
  --muted: <h> <s>% <l>%;
  --muted-foreground: <h> <s>% <l>%;
  --accent: <h> <s>% <l>%;
  --accent-foreground: <h> <s>% <l>%;
  --destructive: <h> <s>% <l>%;
  --destructive-foreground: <h> <s>% <l>%;
  --border: <h> <s>% <l>%;
  --input: <h> <s>% <l>%;
  --ring: <h> <s>% <l>%;
}
```

**Important:** Values use the HSL space-separated format without `hsl()` wrapper (e.g., `222.2 84% 4.9%`), matching shadcn/ui's convention. Tailwind applies `hsl()` at build time via `hsl(var(--primary))`.

### 2. Register the theme

Add an entry to `frontend/lib/themes.ts`:

```ts
export const themes: ThemeDefinition[] = [
  // ... existing themes
  {
    id: "<theme-id>",
    name: "<Theme Name>",
    description: "<Short description>",
    preview: {
      light: { primary: "#hex", secondary: "#hex", background: "#hex", foreground: "#hex" },
      dark:  { primary: "#hex", secondary: "#hex", background: "#hex", foreground: "#hex" },
    },
  },
];
```

### 3. Import the CSS

Add the import to the theme CSS barrel (either `globals.css` or `themes/index.css`):

```css
@import "./themes/<theme-id>.css";
```

### 4. Test both modes

- Switch to the new theme in the theme picker
- Verify light mode: check cards, buttons, inputs, navigation, destructive actions
- Verify dark mode: same checks, ensure sufficient contrast
- Test on mobile viewport
- Verify the theme picker swatch preview matches the actual theme

---

## How to Modify an Existing Theme

1. Open the theme's CSS file in `frontend/styles/themes/<theme-id>.css`
2. Adjust the HSL values for the desired variables
3. Both the `[data-theme="<id>"]` (light) and `[data-theme="<id>"].dark` blocks must be updated if the change affects both modes
4. Use browser DevTools to live-edit `--variable` values for rapid iteration
5. Check contrast ratios — WCAG AA requires 4.5:1 for normal text, 3:1 for large text

### Tips for Good Themes

- **Background/foreground contrast**: Ensure at least 7:1 ratio for body text (WCAG AAA)
- **Primary on primary-foreground**: Must be readable as button text
- **Muted-foreground on muted**: Used for placeholder text, needs 4.5:1 minimum
- **Destructive**: Keep clearly red-ish for semantic meaning, even in creative themes
- **Border and input**: Should be subtle but visible in both modes
- **Dark mode**: Don't just invert — use slightly desaturated, lower-lightness backgrounds with brighter foregrounds
- **Test with real content**: Use the app's actual pages, not just color swatches

### Useful Tools

- [HSL Color Picker](https://hslpicker.com/) — Convert between hex and HSL
- [Realtime Colors](https://www.realtimecolors.com/) — Preview color systems on realistic UI
- [Contrast Checker](https://webaim.org/resources/contrastchecker/) — Verify WCAG compliance
- [shadcn/ui Themes](https://ui.shadcn.com/themes) — Reference themes in the same CSS variable format
