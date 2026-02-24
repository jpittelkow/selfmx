# Changelog Page Roadmap

Add a user-facing changelog page in the frontend so users can see what's new, what changed, and what was fixed across application versions.

**Priority**: MEDIUM  
**Status**: Phases 1-3 Complete  
**Last Updated**: 2026-02-14

**Dependencies**:
- [Versioning System](versioning-system-roadmap.md) - Version numbers, VERSION file, release workflow (Phases 1-3 complete)

---

## Task Checklist

### Phase 1: Backend - Changelog Data Source (HIGH Priority) -- COMPLETE
- [x] Create a `CHANGELOG.md` file in the project root (Keep a Changelog format)
- [x] Create a backend API endpoint (`GET /api/changelog`) that serves changelog entries
- [x] Parse `CHANGELOG.md` (Keep a Changelog format) with ChangelogService
- [x] Support pagination for large changelogs
- [x] Include version number, date, and categorized entries (Added, Changed, Fixed, Removed, Security)

### Phase 2: Frontend - Changelog Page (HIGH Priority) -- COMPLETE
- [x] Create changelog page at `frontend/app/(dashboard)/configuration/changelog/page.tsx`
- [x] Display changelog entries grouped by version with release dates
- [x] Use categorized sections with color-coded badges (Added, Changed, Fixed, Removed, Security)
- [x] Style with existing UI components (cards, badges, collapsible)
- [x] Mobile-responsive layout (mobile-first CSS)
- [x] Add loading skeleton and empty states

### Phase 3: Navigation & Discovery (MEDIUM Priority) -- COMPLETE
- [x] Add "Changelog" to Configuration > General group in config navigation
- [x] Sidebar version footer links to changelog page
- [x] Add changelog to backend search index (`search-pages.php`)
- [x] Add changelog to frontend search pages (`search-pages.ts`)

### Phase 4: Release Integration (LOW Priority) -- PARTIAL
- [x] Auto-generate changelog entries from commit messages during `push.ps1` release
- [x] Update `CHANGELOG.md` automatically on release (ties into versioning roadmap Phase 3 optional task)
- [ ] Consider a "What's New" modal/banner on first login after an update

---

## Proposed Implementation

### Data Format (Keep a Changelog)

Use the [Keep a Changelog](https://keepachangelog.com/) format in `CHANGELOG.md`:

```markdown
# Changelog

## [1.2.0] - 2026-02-10

### Added
- Changelog page for users to see version history
- "What's New" indicator in sidebar

### Fixed
- Settings page layout on mobile devices

## [1.1.0] - 2026-02-01

### Added
- In-app documentation and help center
- PWA install prompt

### Changed
- Improved notification delivery reliability
```

### API Response

```json
{
  "data": [
    {
      "version": "1.2.0",
      "date": "2026-02-10",
      "categories": {
        "added": [
          "Changelog page for users to see version history",
          "\"What's New\" indicator in sidebar"
        ],
        "fixed": [
          "Settings page layout on mobile devices"
        ]
      }
    }
  ],
  "meta": {
    "current_page": 1,
    "last_page": 3
  }
}
```

### Frontend Page Structure

```
frontend/app/(dashboard)/configuration/changelog/page.tsx   # Main changelog page
```

Each version displayed as a card with:
- Version number badge and release date
- Categorized list items with color-coded category labels
- Expandable/collapsible for older versions

---

## Files to Create

**New Files**:
- `CHANGELOG.md` - Changelog entries (Keep a Changelog format)
- `backend/app/Http/Controllers/Api/ChangelogController.php` - API endpoint
- `backend/app/Services/ChangelogService.php` - Parse and serve changelog data
- `frontend/app/(dashboard)/configuration/changelog/page.tsx` - Changelog page

**Files to Modify**:
- `backend/routes/api.php` - Add changelog route
- `frontend/components/sidebar.tsx` - Add changelog nav link
- `frontend/lib/search-pages.ts` - Add changelog to search index

---

## Related Roadmaps

- [Versioning System Roadmap](versioning-system-roadmap.md) - VERSION file, bump script, GitHub release workflow
- [In-App Documentation Roadmap](in-app-documentation-roadmap.md) - Help center pattern (similar user-facing content)
