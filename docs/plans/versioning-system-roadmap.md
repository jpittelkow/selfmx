# Versioning System Roadmap

Implement a versioning system that displays the application version in the settings/configuration area and provides automated version management via GitHub Actions.

**Priority**: MEDIUM  
**Status**: Phase 1–3 Complete (Version Display, Bump Script, GitHub Actions) ✅ - Phase 4 Pending (Version Checking)  
**Last Updated**: 2026-01-30

**Dependencies**:
- [Settings Restructure](settings-restructure-roadmap.md) - Configuration page where version is displayed

---

## Task Checklist

### Version Display (HIGH Priority) ✅ COMPLETE
- [x] Create version configuration file with current version (VERSION file exists)
- [x] Display version in settings/configuration footer
- [x] Display version in main sidebar footer (always visible when logged in)
- [x] Display version on landing page (public visibility)
- [x] Add version to API health check endpoint (backend endpoint exists at `/api/version`)
- [x] Show version in "About" section or modal

### Version Management (HIGH Priority) ✅ COMPLETE
- [x] Create VERSION file in project root
- [x] Create script to update version across codebase ([scripts/bump-version.sh](../../scripts/bump-version.sh))
- [x] Update package.json version (frontend)
- [x] Auto-sync version files from tag push (tag is single source of truth)
- [ ] Update composer.json version (backend) — skipped (not standard for Laravel)
- [ ] Generate CHANGELOG entries — manual for now

### GitHub Action Automation (MEDIUM Priority) ✅ COMPLETE
- [x] Create GitHub Action for version bump on release
- [x] Support semantic versioning (major.minor.patch)
- [x] Auto-generate release notes
- [x] Tag releases automatically
- [x] Trigger release on version tag push (`v*` tags)
- [ ] Optional: Auto-update CHANGELOG.md

### Version Checking (LOW Priority)
- [ ] Add version check endpoint to backend API
- [ ] Compare running version vs latest release
- [ ] Display update notification in admin area (when newer version available)

---

## Current State

**Problems**:
- No centralized version number
- Version not displayed anywhere in the UI
- Manual version management is error-prone
- No automated release process

**Goal**: Single source of truth for version that is:
- Displayed to users in the UI
- Updated automatically via GitHub Actions
- Consistent across frontend, backend, and documentation

---

## Proposed Implementation

### 1. Version File Structure

Create a central VERSION file and supporting configuration:

```
selfmx/
├── VERSION                    # Single source of truth: "1.0.0"
├── version.json              # Extended version info
├── frontend/
│   └── config/version.ts     # Frontend version export
└── backend/
    └── config/version.php    # Backend version config
```

**version.json** (detailed version info):
```json
{
  "version": "1.0.0",
  "build": "2026-01-27T12:00:00Z",
  "commit": "abc1234",
  "channel": "stable"
}
```

### 2. Version Display Locations

**Settings/Configuration Area**:
- Footer of configuration page: "selfmx v1.0.0"
- System info section with detailed version

**API Endpoint**:
```
GET /api/health
{
  "status": "healthy",
  "version": "1.0.0",
  "build": "2026-01-27T12:00:00Z"
}
```

**Frontend**:
- Footer component (optional)
- About modal in user dropdown
- Settings page footer

### 3. Version Update Script

Create a script to update version across all files:

```bash
# scripts/bump-version.sh
#!/bin/bash
# Usage: ./scripts/bump-version.sh 1.2.0

NEW_VERSION=$1

# Update VERSION file
echo "$NEW_VERSION" > VERSION

# Update package.json
npm --prefix frontend version "$NEW_VERSION" --no-git-tag-version

# Update composer.json
# Update version.json with build info
# etc.
```

### 4. GitHub Action Workflow

**.github/workflows/release.yml** (as implemented):

The release workflow supports two trigger methods:

**Method 1: Manual dispatch** (Actions > Release > Run workflow)
- **bump-and-release** job: Uses `scripts/bump-version.sh` with version type (patch/minor/major/custom) or custom version string. Updates `VERSION` and `frontend/package.json`, commits, tags with `v{version}`, pushes to origin, and creates a GitHub Release with auto-generated notes.
- **resolve-version** job: Reads version from bump-and-release outputs.
- **release** job: Builds the Docker image and pushes to GitHub Container Registry (ghcr.io).
- Inputs: `version_type` (patch/minor/major/custom), `custom_version` (required when type=custom).

**Method 2: Tag push** (push a `v*` tag to trigger automatically)
- Push a version tag (e.g. `git tag v1.2.3 && git push --tags`) to trigger the release.
- **sync-and-release** job: Extracts the version from the tag name, runs `scripts/bump-version.sh` to update `VERSION` and `frontend/package.json` on master, commits the changes, and creates a GitHub Release with auto-generated notes.
- **release** job: Builds the Docker image and pushes to GHCR.
- No need to manually update version files -- the tag is the single source of truth and all files are synced automatically.

Both methods produce the same output: a GitHub Release with auto-generated notes, version files synced on master, and a Docker image pushed to GHCR with semver tags (e.g. `1.2.3`), major.minor (`1.2`), major (`1`), SHA, and `latest`.

---

## Implementation Plan

### Phase 1: Version File & Display

1. **Create VERSION file**
   - Add `VERSION` file to project root with initial version (e.g., `0.1.0`)
   - Create `version.json` with extended info

2. **Frontend version display**
   - Create `frontend/config/version.ts` that reads version
   - Add version display to settings/configuration footer
   - Consider adding "About" section to user dropdown

3. **Backend version endpoint**
   - Add version to health check response
   - Create version config in Laravel

### Phase 2: Version Update Script

1. **Create bump script**
   - `scripts/bump-version.sh` for version updates
   - Update VERSION, package.json, composer.json
   - Update version.json with build timestamp

2. **Document manual process**
   - How to bump version locally
   - When to bump (before release)

### Phase 3: GitHub Action

1. **Create release workflow**
   - Manual trigger with version type selection
   - Automated version calculation
   - Commit, tag, and release creation

2. **Test workflow**
   - Test with patch bump
   - Test with minor bump
   - Test with custom version

### Phase 4: Update Notifications (Future)

1. **Version check API**
   - Endpoint to check for updates
   - Compare against GitHub releases

2. **Admin notification**
   - Show notification when update available
   - Link to release notes

---

## Files to Create

**New Files**:
- `VERSION` - Version number file (e.g., "0.1.0")
- `version.json` - Extended version metadata
- `frontend/config/version.ts` - Frontend version module
- `backend/config/version.php` - Backend version config
- `scripts/bump-version.sh` - Version update script
- `.github/workflows/release.yml` - Release automation

**Files to Modify**:
- `frontend/package.json` - Ensure version field exists
- `backend/composer.json` - Ensure version field exists
- `frontend/app/(dashboard)/configuration/page.tsx` - Add version display
- `backend/routes/api.php` - Add version to health endpoint

---

## Semantic Versioning Guide

Follow [Semantic Versioning 2.0.0](https://semver.org/):

- **MAJOR** (1.x.x → 2.0.0): Breaking changes, incompatible API changes
- **MINOR** (1.0.x → 1.1.0): New features, backwards compatible
- **PATCH** (1.0.0 → 1.0.1): Bug fixes, backwards compatible

**Pre-release versions**: `1.0.0-alpha.1`, `1.0.0-beta.1`, `1.0.0-rc.1`

---

## Version Display Mockup

**Configuration Page Footer**:
```
┌─────────────────────────────────────────────────────────────┐
│  Configuration                                              │
│  ┌──────────────┐  ┌──────────────────────────────────────┐│
│  │ System       │  │                                      ││
│  │ Users        │  │  [Configuration Content]             ││
│  │ ...          │  │                                      ││
│  └──────────────┘  └──────────────────────────────────────┘│
│                                                             │
│  ─────────────────────────────────────────────────────────  │
│  selfmx v1.0.0 • Build 2026-01-27                          │
└─────────────────────────────────────────────────────────────┘
```

**User Dropdown "About"**:
```
┌────────────────┐
│ My Profile     │
│ Preferences    │
│ ────────────── │
│ About          │ → Opens modal with version info
│ Sign Out       │
└────────────────┘
```

---

## Related Roadmaps

- [Settings Restructure Roadmap](settings-restructure-roadmap.md) - Configuration page where version displays
- [Branding & UI Consistency Roadmap](branding-ui-consistency-roadmap.md) - App name shown with version
