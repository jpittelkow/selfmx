# User Mail Settings — Sidebar Section & Per-User Spam Filtering

Move mail-related user settings (spam filter, mail rules, and future mail preferences) out of scattered locations and into a dedicated **"Mail Settings"** section accessible from the main sidebar. Make spam filtering a per-user feature instead of a global admin config item.

**Status**: Phases 1, 3, 4 complete; Phase 2 partially complete; Phase 5 in progress (import page done)
**Priority**: Medium — UX improvement, groups related features logically
**Created**: 2026-03-03

---

## Motivation

Currently, mail-related user settings are scattered:
- **Mail Rules** live at `/user/rules` (buried in user profile settings)
- **Spam Filter** (allow/block lists) lives at `/configuration/spam-filter` (admin-only, global)
- There's no single place for a user to manage all their mail preferences

Problems:
1. Spam filtering is a **per-user** concern — each user should control their own allow/block lists — but it's currently a global admin setting
2. Mail rules and spam settings are logically related but live in completely different parts of the UI
3. Users have no obvious path to find mail-related settings from the main sidebar

## Implementation Plan

### Phase 1: Create "Mail Settings" Section in Sidebar

Add a new nav section to the main sidebar (below mail folders/labels, above Contacts) that's visible to **all authenticated users** (not admin-gated):

```
Mail Folders (Inbox, Sent, Spam, etc.)
Labels
─────────────────
Mail Settings        ← NEW section
  ├─ Rules
  ├─ Spam Filter
  └─ (future: Signatures, Auto-Reply, etc.)
─────────────────
Contacts
```

**Files to modify:**
- `frontend/components/sidebar.tsx` — Add "Mail Settings" collapsible section or nav group between Labels and the separator before Contacts
- Import `ListFilter`, `ShieldBan`, `Settings` (or `MailCog`) icons from lucide-react

**Collapsed sidebar behavior:** Show a single `Settings` or `MailCog` icon that links to `/mail/settings`

### Phase 2: Per-User Spam Filter (Backend)

Convert spam filtering from global/admin to per-user:

**Backend changes:**
- `backend/app/Http/Controllers/Api/SpamFilterController.php` — Ensure all queries scope to `$request->user()->id` (check if already user-scoped or if it's system-wide)
- `backend/database/migrations/` — If `spam_filter_entries` table lacks a `user_id` column, add migration to add it
- `backend/routes/api.php` — Ensure spam filter CRUD routes are under auth middleware (not admin-only)
- Remove the `permission: "settings.view"` gate from the spam filter if it exists on backend routes

**Key consideration:** The admin-level global spam filter in `/configuration/spam-filter` may still be useful as a system-wide block list. Consider keeping it as an admin override that applies to all users, while per-user lists only affect that user's mail. Evaluation:
- **Option A**: Replace global with per-user only (simpler)
- **Option B**: Keep both — admin global list + per-user lists (more flexible, recommended)

### Phase 3: Move Pages to New Routes

Create new frontend routes under `/mail/settings/`:

| Old Route | New Route | Notes |
|-----------|-----------|-------|
| `/user/rules` | `/mail/settings/rules` | Move page, add redirect from old path |
| `/configuration/spam-filter` | `/mail/settings/spam` | Move to user context, add redirect |
| (new) | `/mail/settings` | Index/landing page for mail settings |

**Files to create:**
- `frontend/app/(dashboard)/mail/settings/page.tsx` — Mail settings index (links to sub-pages)
- `frontend/app/(dashboard)/mail/settings/rules/page.tsx` — Move from `/user/rules`
- `frontend/app/(dashboard)/mail/settings/spam/page.tsx` — Per-user spam filter (adapted from config version)
- `frontend/app/(dashboard)/mail/settings/layout.tsx` — Optional: shared layout with sub-nav

**Files to modify:**
- `frontend/app/(dashboard)/user/rules/page.tsx` — Replace with redirect to `/mail/settings/rules`
- `frontend/app/(dashboard)/user/layout.tsx` — Remove "Rules" from user settings nav
- `frontend/app/(dashboard)/configuration/layout.tsx` — Remove "Spam Filter" from Email Hosting group (or keep as admin-level global override if Option B)

### Phase 4: Update Navigation & Search Registration

- `frontend/components/sidebar.tsx` — Wire up "Mail Settings" links
- `frontend/lib/search-pages.ts` — Update search entries for new routes
- `backend/config/search-pages.php` — Update search entries for new routes
- `frontend/app/(dashboard)/configuration/layout.tsx` — If keeping admin spam filter (Option B), rename to "Global Spam Filter" to distinguish from per-user

### Phase 5: Future Mail Settings (Stretch)

Once the section exists, these naturally belong here:
- **Email Signatures** — Per-user compose signatures
- **Auto-Reply / Vacation Responder** — Out-of-office auto-replies
- **Mail Display Preferences** — Thread view vs. conversation view, density, preview lines
- **Import/Export** — Move email import from user profile to mail settings
- **Notification Preferences** — Per-mailbox notification settings

---

## Success Criteria

- Users can access Mail Settings directly from the main sidebar
- Each user has their own spam allow/block list
- Mail rules and spam filter are grouped together under Mail Settings
- Old routes redirect to new locations (no broken bookmarks)
- Admin can still manage a global block list (if Option B chosen)
- Search registration updated for new routes
- All changes include test coverage

## Dependencies

- None — can be started independently of bug fixes or Phase 7/8 work
