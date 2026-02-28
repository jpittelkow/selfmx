# Recipe: Add a Color Theme

Add a new pre-set color theme to Sourdough. Each theme defines colors, radius, font sizes, font weights, spacing, and layout sizing for both light and dark modes.

## Files to Create/Modify

| File | Action | Purpose |
|------|--------|---------|
| `frontend/styles/themes/<theme-id>.css` | Create | Theme CSS with all variables |
| `frontend/lib/themes.ts` | Modify | Register theme in the registry |
| `frontend/app/globals.css` | Modify | Add `@import` for the new theme CSS |

## Reference Implementations

- **Default theme**: `frontend/styles/themes/default.css` — the baseline theme with all variable groups
- **Theme registry**: `frontend/lib/themes.ts` — metadata format and preview colors
- **Theming pattern**: `docs/ai/patterns/theming.md` — architecture overview

## Critical Rules

1. **Both modes required** — Every theme MUST define both `[data-theme="<id>"]:not(.dark)` (light) and `[data-theme="<id>"].dark` (dark) selectors. The `:not(.dark)` is required for CSS specificity — without it, the `:root` fallback in globals.css overrides theme light colors.

2. **HSL space-separated format** — Color values use `H S% L%` without `hsl()` wrapper (e.g., `222.2 84% 4.9%`). Tailwind applies `hsl()` at build time.

3. **Keep destructive red-ish** — The `--destructive` color should remain clearly red/danger-like for semantic meaning, even in creative themes.

4. **WCAG contrast ratios** — Minimum 4.5:1 for normal text, 3:1 for large text. Key pairs to check:
   - `--foreground` on `--background` (body text)
   - `--primary-foreground` on `--primary` (buttons)
   - `--muted-foreground` on `--muted` (placeholder text)
   - `--card-foreground` on `--card` (card content)

5. **Test with real content** — Don't just check color swatches. Navigate through dashboard, config pages, forms, modals, and toasts in both modes.

6. **Preview colors must be accurate** — The hex colors in `themes.ts` should match the actual CSS theme. Convert HSL to hex for the preview.

## Step-by-Step

### 1. Create the CSS file

Create `frontend/styles/themes/<theme-id>.css`. Use the full template below — every variable is required.

```css
/* Theme: <Theme Name> */
/* Description: <Short description> */

[data-theme="<theme-id>"]:not(.dark) {
  /* ── Colors (light mode) ── */
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

  /* ── Radius ── */
  --radius: 0.5rem;
  --radius-sm: 0.25rem;
  --radius-lg: 0.75rem;
  --radius-xl: 1rem;
  --radius-full: 9999px;

  /* ── Font sizes ── */
  --text-xs: 0.75rem;
  --text-sm: 0.875rem;
  --text-base: 1rem;
  --text-lg: 1.125rem;
  --text-xl: 1.25rem;
  --text-2xl: 1.5rem;
  --text-3xl: 1.875rem;

  /* ── Font weights ── */
  --font-weight-normal: 400;
  --font-weight-medium: 500;
  --font-weight-semibold: 600;
  --font-weight-bold: 700;

  /* ── Spacing ── */
  --spacing-xs: 0.25rem;
  --spacing-sm: 0.5rem;
  --spacing-md: 1rem;
  --spacing-lg: 1.5rem;
  --spacing-xl: 2rem;
  --spacing-2xl: 3rem;

  /* ── Layout sizing ── */
  --sidebar-width: 16rem;
  --header-height: 3.5rem;
  --container-max-width: 80rem;
  --card-padding: 1.5rem;
}

[data-theme="<theme-id>"].dark {
  /* ── Colors (dark mode) ── */
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

  /* Non-color variables only need to be repeated if they differ from light mode.
     Radius, font sizes, spacing, sizing are typically the same in both modes. */
}
```

### 2. Register the theme

Add an entry to the `themes` array in `frontend/lib/themes.ts`:

```ts
{
  id: "<theme-id>",
  name: "<Theme Name>",
  description: "<Short description>",
  preview: {
    light: {
      primary: "#hex",
      secondary: "#hex",
      background: "#hex",
      foreground: "#hex",
    },
    dark: {
      primary: "#hex",
      secondary: "#hex",
      background: "#hex",
      foreground: "#hex",
    },
  },
},
```

### 3. Import the CSS

Add an import to `frontend/app/globals.css` alongside the existing theme imports:

```css
@import "../styles/themes/<theme-id>.css";
```

### 4. Test both modes

- [ ] Switch to the new theme (set `data-theme` in DevTools: `document.documentElement.setAttribute('data-theme', '<theme-id>')`)
- [ ] Light mode: check dashboard, cards, buttons, inputs, navigation, destructive actions, toasts
- [ ] Dark mode: same checks — ensure sufficient contrast
- [ ] Mobile viewport: check responsive layout
- [ ] Verify theme picker swatch preview matches actual theme colors
- [ ] Run `npm run build` — no errors
- [ ] Run `npm test` — all tests pass

## Variable Reference

### Colors

| Variable | Purpose | Used By |
|----------|---------|---------|
| `--background` | Page background | `bg-background` |
| `--foreground` | Default text color | `text-foreground` |
| `--card` | Card/surface background | `bg-card` |
| `--card-foreground` | Card text color | `text-card-foreground` |
| `--popover` | Popover/dropdown background | `bg-popover` |
| `--popover-foreground` | Popover text color | `text-popover-foreground` |
| `--primary` | Primary action (buttons, links) | `bg-primary`, `text-primary` |
| `--primary-foreground` | Text on primary background | `text-primary-foreground` |
| `--secondary` | Secondary surfaces | `bg-secondary` |
| `--secondary-foreground` | Text on secondary background | `text-secondary-foreground` |
| `--muted` | Muted/subtle background | `bg-muted` |
| `--muted-foreground` | Muted text (placeholders, hints) | `text-muted-foreground` |
| `--accent` | Accent/hover states | `bg-accent` |
| `--accent-foreground` | Text on accent background | `text-accent-foreground` |
| `--destructive` | Danger/delete actions | `bg-destructive` |
| `--destructive-foreground` | Text on destructive background | `text-destructive-foreground` |
| `--border` | Default border color | `border-border` |
| `--input` | Input border color | `border-input` |
| `--ring` | Focus ring color | `ring-ring` |

### Radius

| Variable | Purpose | Tailwind |
|----------|---------|----------|
| `--radius` | Default border radius | `rounded-md` |
| `--radius-sm` | Small radius | `rounded-sm` |
| `--radius-lg` | Large radius | `rounded-lg` |
| `--radius-xl` | Extra-large radius | `rounded-xl` |
| `--radius-full` | Fully rounded (pill) | `rounded-full` |

### Font Sizes

| Variable | Default | Tailwind |
|----------|---------|----------|
| `--text-xs` | 0.75rem (12px) | `text-xs` |
| `--text-sm` | 0.875rem (14px) | `text-sm` |
| `--text-base` | 1rem (16px) | `text-base` |
| `--text-lg` | 1.125rem (18px) | `text-lg` |
| `--text-xl` | 1.25rem (20px) | `text-xl` |
| `--text-2xl` | 1.5rem (24px) | `text-2xl` |
| `--text-3xl` | 1.875rem (30px) | `text-3xl` |

### Font Weights

| Variable | Default | Use |
|----------|---------|-----|
| `--font-weight-normal` | 400 | Body text |
| `--font-weight-medium` | 500 | Labels, emphasis |
| `--font-weight-semibold` | 600 | Headings, buttons |
| `--font-weight-bold` | 700 | Strong emphasis |

### Spacing

| Variable | Default | Tailwind |
|----------|---------|----------|
| `--spacing-xs` | 0.25rem (4px) | `p-theme-xs`, `m-theme-xs` |
| `--spacing-sm` | 0.5rem (8px) | `p-theme-sm`, `m-theme-sm` |
| `--spacing-md` | 1rem (16px) | `p-theme-md`, `m-theme-md` |
| `--spacing-lg` | 1.5rem (24px) | `p-theme-lg`, `m-theme-lg` |
| `--spacing-xl` | 2rem (32px) | `p-theme-xl`, `m-theme-xl` |
| `--spacing-2xl` | 3rem (48px) | `p-theme-2xl`, `m-theme-2xl` |

### Layout Sizing

| Variable | Default | Purpose |
|----------|---------|---------|
| `--sidebar-width` | 16rem (256px) | Sidebar width |
| `--header-height` | 3.5rem (56px) | Top header height |
| `--container-max-width` | 80rem (1280px) | Main content max width |
| `--card-padding` | 1.5rem (24px) | Default card inner padding |

## Tips for Good Themes

- **Start from an existing theme** — Copy `default.css` and modify values. Much faster than starting from scratch.
- **Dark mode is not an invert** — Use slightly desaturated, lower-lightness backgrounds with brighter foregrounds. Don't just swap light/dark.
- **Test destructive actions** — Delete buttons, error toasts, validation messages should always be clearly red-ish.
- **Check borders in dark mode** — `--border` and `--input` must be visible but subtle against dark backgrounds.
- **Use real pages** — Test with actual dashboard, config, forms, modals, and toast notifications. Color swatches alone are insufficient.

## Useful Tools

- [HSL Color Picker](https://hslpicker.com/) — Convert between hex and HSL
- [Realtime Colors](https://www.realtimecolors.com/) — Preview color systems on realistic UI
- [WCAG Contrast Checker](https://webaim.org/resources/contrastchecker/) — Verify accessibility compliance
- [shadcn/ui Themes](https://ui.shadcn.com/themes) — Reference themes using the same CSS variable format
