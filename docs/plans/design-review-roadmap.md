# Design Review: Sourdough Frontend Port

Port all frontend/UI changes from sourdough commit [`487e110`](https://github.com/Sourdough-start/sourdough/commit/487e110ceff8499e8131af01a8b24f0872c78031) into selfmx. ~48 frontend files changed across 8 workstreams.

**Status**: Complete ✅
**Source**: Sourdough v0.10.0 (commit 487e110)
**Scope**: 8 workstreams — component extraction, design system, help center, user management, notifications, auth, preferences, polish

---

## Workstream 1: New Dependencies & Shared Utilities

Foundation work — must be done first since other workstreams depend on these.

### New Packages
- [x] Install `@tanstack/react-table` ^8.21.3 — Headless table library (used by DataTable, user-table)
- [x] Install `highlight.js` ^11.11.1 — Syntax highlighting for help articles
- [x] Install `rehype-highlight` ^7.0.2 — Rehype plugin for highlight.js + React Markdown

### Shared Utilities
- [x] `frontend/lib/utils.ts` — Extract `getInitials(name: string)` utility (currently duplicated in user-dropdown.tsx and profile page)

### Global CSS
- [x] `frontend/app/globals.css` (+58/-4) — Add highlight.js theme-aware syntax highlighting tokens (`.hljs-comment`, `.hljs-keyword`, `.hljs-string`, etc.) with light/dark mode CSS custom properties

---

## Workstream 2: Design System & Navigation Polish

Cross-cutting design changes that affect the entire app's look and feel.

### Sidebar Redesign
- [x] `frontend/components/sidebar.tsx` (+77/-52)
  - Background: `bg-background` → `bg-muted/30` (subtle tint)
  - Active nav item: replace `bg-muted` with `bg-primary/10 text-primary font-medium border-l-2 border-primary` (left accent border)
  - Add `Tooltip` wrappers on collapsed sidebar items ("Home", "Configuration", "Expand sidebar")
  - Add `transition-colors duration-150` and `hover:bg-accent` for hover states
  - Mobile sheet bottom padding: `env(safe-area-inset-bottom)` for iPhone notch
  - Buttons: use `asChild` with `Link` inside instead of wrapping `Link` around `Button`

### Header Microinteractions
- [x] `frontend/components/header.tsx` (+4/-9)
  - Remove vertical `Separator` dividers between header action groups
  - Add `transition-transform duration-150 hover:scale-[1.03]` on mobile menu and search buttons
  - Add `safe-area-top` class
  - Increase gap from `gap-1` to `gap-2`

### Configuration Nav Consistency
- [x] `frontend/app/(dashboard)/configuration/layout.tsx` (+12/-16)
  - Active nav styling: match sidebar pattern (`bg-primary/10 text-primary font-medium border-l-2 border-primary`)
  - Nav item descriptions: `hidden group-hover:block`
  - Chevron: single `ChevronDown` with `-rotate-90` transform instead of icon swap
  - Loading spinner: CSS spinner → `Loader2` icon

### CollapsibleCard Enhancement
- [x] `frontend/components/ui/collapsible-card.tsx` (+7/-1)
  - Add `intent` prop (`"default" | "danger" | "info"`) for visual tinting
  - `danger` → `border-destructive/30`, `info` → `border-primary/20`

### User Dropdown Cleanup
- [x] `frontend/components/user-dropdown.tsx` (+31/-43)
  - Use shared `getInitials` from `lib/utils.ts` (remove local copy)
  - Replace `AlertDialog` with `Dialog` for logout confirmation (softer feel)
  - Simplify user name + admin badge layout (inline badge, remove nested div)

### About Dialog
- [x] `frontend/components/about-dialog.tsx` (+77/-27)
  - Add `Logo` component at top with version link to changelog
  - Title/description: `sr-only` (visually hidden, accessible)
  - System Information: wrap in `Collapsible` (defaults open for admins)
  - Animated `ChevronDown` toggle

---

## Workstream 3: AI Provider Component Extraction

Decompose the monolithic AI configuration page (1323 lines removed from page) into 5 focused components.

### New Component Files
- [x] `frontend/components/ai/ai-types.ts` (79 lines) — TypeScript interfaces: `AIProvider`, `DiscoveredModel`, `ProviderTemplate`, `LLMMode`, plus `providerTemplates` array (Claude, OpenAI, Gemini, Ollama, Azure OpenAI, AWS Bedrock)
- [x] `frontend/components/ai/orchestration-mode-card.tsx` (96 lines) — 3-tab Tabs component (Single/Aggregation/Council) with Alert descriptions and "Best for" guidance
- [x] `frontend/components/ai/provider-card.tsx` (159 lines) — Collapsible provider card with edit/test/primary/toggle/delete actions. Uses `CollapsibleCard`, `Switch`, `ProviderIcon`
- [x] `frontend/components/ai/provider-dialog.tsx` (743 lines) — Full add/edit dialog: template selection, credential fields, inline API key validation (CheckCircle/XCircle), model discovery with sessionStorage caching (1-hour TTL), model selection via Select dropdown
- [x] `frontend/components/ai/provider-list-card.tsx` (83 lines) — Container card listing all providers with "Add Provider" button and empty state

### Page Update
- [x] `frontend/app/(dashboard)/configuration/ai/page.tsx` (1473 → 189 lines) — Replace inline code with imports from new components

---

## Workstream 4: SSO Component Extraction

Decompose the SSO configuration page (529 lines removed from page) into 4 focused components.

### New Component Files
- [x] `frontend/components/sso/types.ts` (147 lines) — Zod schema, default values, provider metadata, utility functions (`toBool`, `getRedirectUri`, `copyRedirectUri`, `getProviderKeys`, `GLOBAL_KEYS`). Validation: Google client IDs must end `.apps.googleusercontent.com`, Microsoft IDs must be UUIDs, OIDC issuer must use HTTPS
- [x] `frontend/components/sso/sso-global-options-card.tsx` (82 lines) — Card with 4 `SettingsSwitchRow` toggles
- [x] `frontend/components/sso/sso-provider-card.tsx` (196 lines) — Generic OAuth provider card with status badge, redirect URI copy, test connection, per-provider save
- [x] `frontend/components/sso/sso-oidc-card.tsx` (206 lines) — Enterprise OIDC card with issuer URL, credentials, test connection, setup instructions

### Page Update
- [x] `frontend/app/(dashboard)/configuration/sso/page.tsx` (+53/-529) — Replace inline code with imports from new components

---

## Workstream 5: Help Center Enhancements

Search highlighting, keyboard navigation, TOC sidebar, and syntax highlighting.

### New Files
- [x] `frontend/components/help/help-article-toc.tsx` (97 lines) — Table of contents component. Two variants: "sidebar" (persistent right panel) and "inline" (collapsible on mobile). Uses `useActiveHeading` hook for scroll-spy highlighting. Smooth scroll-to-heading on click
- [x] `frontend/lib/help/help-toc.ts` (151 lines) — TOC utilities: `TocHeading` interface, `slugify()`, `stripInlineMarkdown()`, `childrenToText()`, `getRadixViewport()`, `extractHeadings()` (returns null if <3 headings), `useActiveHeading()` hook (IntersectionObserver-based scroll spy)

### Modified Files
- [x] `frontend/components/help/help-article.tsx` (+45/-6)
  - Add syntax highlighting via `rehype-highlight` with registered languages (bash, javascript, json, graphql)
  - Auto-generated `id` slugs on headings via `makeHeading()` for TOC linking
  - Typography bump: h1→2xl, h2→xl, h3→lg; paragraph text `text-foreground`; `prose-headings:font-heading`
- [x] `frontend/components/help/help-center-modal.tsx` (+70/-14)
  - Dialog widened: `max-w-5xl` → `max-w-6xl`
  - Desktop TOC sidebar (right panel, 48px wide, `hidden lg:block`)
  - Mobile inline TOC (collapsible, `lg:hidden`)
  - Mobile category tab bar when viewing articles
  - Search bar always visible (was hidden when viewing article)
  - Category grid: icon in colored circle (`bg-primary/10`), hover effect (`group-hover:bg-primary/15`)
  - Content transitions: `animate-in fade-in duration-200`
- [x] `frontend/components/help/help-search.tsx` (+114/-18)
  - Keyboard navigation (ArrowUp/ArrowDown/Enter/Escape) with active index tracking
  - Match highlighting using Fuse.js match indices (`<mark>` tags with yellow background)
  - ARIA attributes: `role="combobox"`, `aria-expanded`, `aria-activedescendant`, `role="listbox"`, `role="option"`, `aria-selected`
  - Active result: `bg-accent text-accent-foreground`
- [x] `frontend/components/help/help-sidebar.tsx` (+50/-32)
  - Replace raw buttons with `Collapsible`/`CollapsibleTrigger`/`CollapsibleContent`
  - Article count `Badge` next to category name
  - Articles use `Button variant="ghost"`
  - Open/close animation via Radix data attributes
- [x] `frontend/lib/help/help-search.ts` (+12/-3)
  - Add `HelpSearchResult` interface with optional `FuseResultMatch[]`
  - Enable `includeMatches: true` in Fuse options

### Minor Tweaks
- [x] `frontend/components/help/help-link.tsx` (+2/-2)
- [x] `frontend/components/help/download-docs-button.tsx` (+1/-1) — N/A (selfmx doesn't have GraphQL API docs)

---

## Workstream 6: User Management & DataTable

New reusable DataTable component, user table rewrite with bulk actions, avatar upload, and security overview.

### New Components
- [x] `frontend/components/ui/data-table.tsx` (149 lines) — Generic `DataTable<TData, TValue>` on `@tanstack/react-table`. Server-side sorting, row selection, column metadata for className, sort direction indicators (ArrowUp/ArrowDown/ArrowUpDown). Extends `ColumnMeta` via module augmentation
- [x] `frontend/components/user/avatar-upload.tsx` (166 lines) — File input (2MB, JPEG/PNG/GIF/WebP), instant blob URL preview, `POST /profile/avatar` with FormData, `DELETE /profile/avatar`, desktop hover overlay (Camera icon), mobile edit button (Pencil), remove photo button. Uses `getInitials`
- [x] `frontend/components/user/security/security-overview.tsx` (192 lines) — Dashboard card: 2FA status, passkey count, SSO connections, API key count. Feature flags for conditional sections. Color-coded icons (green active, muted inactive)

### Modified Files
- [x] `frontend/components/admin/user-table.tsx` (+337/-180) — Major rewrite:
  - Replace manual `Table/TableRow/TableCell` with new `DataTable`
  - Add row selection with `Checkbox` column and `RowSelectionState`
  - Bulk actions bar: Enable/Disable/Delete selected (confirmation dialog, self-exclusion)
  - User avatar: `Avatar/AvatarFallback/AvatarImage` + `getInitials`
  - `ColumnDef` with typed columns, `meta.className` for hidden columns
- [x] `frontend/app/(dashboard)/configuration/users/page.tsx` (+24/-4)
  - `SortingState` management with default `created_at` desc
  - Pass `sorting`/`onSortingChange` to `UserTable`
  - Sort params sent to API
  - Pagination: "Showing X-Y of Z users"
- [x] `frontend/app/(dashboard)/user/profile/page.tsx` (+31/-34)
  - Replace inline avatar display with `AvatarUpload` component
  - Remove local `getInitials` (use from `lib/utils`)
  - Group memberships card: `UsersRound` icon, admin "Manage groups" link, group badges link to `/configuration/groups`
- [x] `frontend/app/(dashboard)/user/security/page.tsx` (+2/-0)
  - Add `SecurityOverview` component at top

### Security Section Refactors
- [x] `frontend/components/user/security/sessions-section.tsx` (+47/-59) — Refactor from `Card` to `CollapsibleCard` (defaultOpen=false)
- [x] `frontend/components/user/security/api-keys-section.tsx` (+24/-38) — Refactor to `CollapsibleCard`, "Create API Key" button in `headerActions` with `e.stopPropagation()`
- [x] `frontend/components/user/security/password-section.tsx` (+4/-7) — PasswordInput adoption

---

## Workstream 7: Notification UI Polish

Semantic coloring, entrance animations, date grouping, and accessibility.

### Modified Files
- [x] `frontend/lib/notification-types.ts` (+18/-14)
  - Add `color` and `bg` fields to `NotificationTypeMeta`
  - Semantic palette: green (success), red (failure/critical), amber (warning), blue (auth/info)
  - Dark mode variants
- [x] `frontend/components/notifications/notification-bell.tsx` (+14/-12)
  - Redesigned ping: layered `animate-ping` ring behind count badge
  - Ping only shows when dropdown is closed
- [x] `frontend/components/notifications/notification-dropdown.tsx` (+16/-10)
  - Empty state: larger icon, `font-heading`, "When you receive notifications, they'll appear here." subtext
  - Staggered entrance: `animate-in fade-in slide-in-from-bottom-1 duration-300` with CSS class delays in globals.css
- [x] `frontend/components/notifications/notification-item.tsx` (+34/-18)
  - Icon in colored rounded-full background (`typeMeta.bg`) with colored icon (`typeMeta.color`)
  - Unread dot indicator (2px primary circle)
  - Compact mode: timestamp inline right-aligned next to title
  - Mark-as-read: `div[role=button]` → proper `Button` component
- [x] `frontend/app/(dashboard)/notifications/page.tsx` (+92/-50)
  - Date grouping: "Today", "Yesterday", "Earlier this week", "Older" section headers
  - Replace raw HTML checkbox with shadcn `Checkbox`
  - Pagination: "Showing X-Y of Z notifications"
  - Tab content spacing: `mt-6` → `mt-4`
- [x] `frontend/components/notifications/notification-list.tsx` (+1/-1) — Minor

---

## Workstream 8: Auth, Preferences & Remaining Polish

Auth page split-screen, tabbed preferences, and miscellaneous polish across pages.

### Auth Pages
- [x] `frontend/components/auth/auth-page-layout.tsx` (+24/-14)
  - Split-panel layout: decorative left panel (desktop only) with gradient `from-primary/20 via-primary/10 to-background` and centered Logo
  - Form card entrance animation: `animate-in fade-in slide-in-from-bottom-4 duration-500`
  - Logo on mobile moves above form
- [x] `frontend/app/(auth)/login/page.tsx` (+2/-0) — Add `className="h-10"` to email input
- [x] `frontend/app/(auth)/register/page.tsx` (+4/-0) — Add `className="h-10"` to name/email inputs

### User Preferences
- [x] `frontend/app/(dashboard)/user/preferences/page.tsx` (+202/-145)
  - Reorganize into 4 tabs: Appearance, Defaults & Regional, Notifications, PWA & Devices
  - Active Appearance tab card: `border-t-2 border-t-primary` accent
  - Uses `Tabs/TabsList/TabsTrigger/TabsContent` from shadcn/ui
- [x] `frontend/app/(dashboard)/user/layout.tsx` (+2/-1) — Loading spinner: CSS spinner → `Loader2`

### Dashboard & Config Pages
- [x] `frontend/app/(dashboard)/dashboard/page.tsx` — selfmx redirects to `/mail`; intentional divergence, skip
- [x] `frontend/app/(dashboard)/configuration/page.tsx` (+2/-1) — Minor
- [x] `frontend/app/(dashboard)/configuration/branding/page.tsx` (+40/-12) — Styling improvements
- [x] `frontend/app/(dashboard)/configuration/security/page.tsx` (+3/-5) — Minor
- [x] `frontend/app/(dashboard)/configuration/audit/page.tsx` (+149/-112) — Refactoring

### Widget & Component Polish
- [x] `frontend/components/dashboard/widgets/stats-widget.tsx` (+18/-7)
- [x] `frontend/components/dashboard/widgets/welcome-widget.tsx` (+4/-4)
- [x] `frontend/components/dashboard/widgets/quick-actions-widget.tsx` (+4/-1)
- [x] `frontend/components/audit/audit-stats-card.tsx` (+11/-3) — Styling
- [x] `frontend/components/storage/file-browser.tsx` (+1/-1) — Minor
- [x] `frontend/components/ai/ai-settings-form.tsx` (+1/-1) — Minor
- [x] `frontend/app/layout.tsx` (+1/-0) — Minor
- [x] `frontend/components/ui/sheet.tsx` (+1/-1) — Minor

---

## Key Design Patterns from Sourdough

These patterns should be applied consistently throughout the port:

| Pattern | Description | Usage |
|---------|-------------|-------|
| **Left accent active nav** | `bg-primary/10 text-primary font-medium border-l-2 border-primary` | Sidebar, config nav, any active nav item |
| **Component decomposition** | Extract types → list card → item card → dialog | AI page (-1323 lines), SSO page (-529 lines) |
| **CollapsibleCard defaults** | Dense pages default sections to closed | Security sections, provider cards |
| **Notification coloring** | Semantic `color` + `bg` per notification type | Green=success, red=failure, amber=warning, blue=info |
| **Staggered entrance** | `animate-in fade-in slide-in-from-bottom-1` with `animationDelay: i * 50ms` | Notification dropdown items, content transitions |
| **Split-panel auth** | Decorative gradient left panel (desktop), form card with slide-up animation | Login, register pages |
| **Tabbed preferences** | Organize dense settings into categorized tabs | Appearance, Defaults, Notifications, PWA |
| **Tooltip on collapsed icons** | Radix Tooltip wrapping icon buttons when sidebar collapsed | Sidebar, any icon-only state |
| **Mobile-first matrices** | Stacked card layout on mobile for table/matrix data | Notification preferences, user table |
| **TOC scroll-spy** | IntersectionObserver on Radix ScrollArea viewport for active heading | Help center articles |
| **DataTable pattern** | Generic `@tanstack/react-table` wrapper with sorting + selection | User table, reusable for future tables |
| **Shared `getInitials`** | Single utility in `lib/utils.ts` | User dropdown, profile, avatar, user table |

---

## Recommended Port Order

```
Workstream 1 (Dependencies)     ← do first, everything else depends on it
    ↓
Workstream 2 (Design System)    ← sets the visual foundation
    ↓
┌───────────────────────────────────────────┐
│ These can be done in parallel / any order: │
├───────────────────────────────────────────┤
│ Workstream 3 (AI Extraction)              │
│ Workstream 4 (SSO Extraction)             │
│ Workstream 5 (Help Center)                │
│ Workstream 6 (User Mgmt + DataTable)      │
│ Workstream 7 (Notifications)              │
│ Workstream 8 (Auth, Prefs & Polish)       │
└───────────────────────────────────────────┘
```

### Approach

For each file in every workstream:
1. **Diff** sourdough's version against selfmx's current version
2. **Identify** selfmx-specific divergences (provider accounts, email forwarding, etc.)
3. **Port** sourdough's changes, adapting to selfmx's current code where needed
4. **Test** visually in the browser and fix any issues

### Conflict-Prone Areas

| File | Reason |
|------|--------|
| `frontend/app/(dashboard)/configuration/layout.tsx` | selfmx has provider accounts nav entries |
| `frontend/app/(dashboard)/configuration/ai/page.tsx` | selfmx may have diverged with AI features |
| `frontend/app/(dashboard)/configuration/sso/page.tsx` | selfmx may have SSO-specific customizations |
| `frontend/components/sidebar.tsx` | selfmx has email-specific nav items |
| `frontend/app/(dashboard)/user/preferences/page.tsx` | selfmx has email-specific preferences |
