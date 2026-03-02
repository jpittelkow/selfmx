# Recipe: Commit, Push, and Release

Step-by-step guide to commit changes, push to remote, and create a versioned release.

**Quick shortcut:** Use `./scripts/push.ps1 [patch|minor|major] [commit-message]` to automate the entire release process. The Cursor "push" shortcut (`.cursor/rules/push-shortcut.mdc`) delegates to this script after running AI-specific pre-flight checks.

## How Releases Work

selfmx uses a **tag-triggered release workflow** (`.github/workflows/release.yml`). There are two paths:

| Trigger | What happens |
|---------|-------------|
| **Push a `v*` tag** | Workflow syncs `VERSION` + `frontend/package.json` to match the tag, creates a GitHub Release, builds and pushes a Docker image |
| **Manual workflow_dispatch** | Workflow bumps version files, commits, tags, creates release, builds Docker |

**For AI-assisted releases, always use the tag push path.** The workflow handles version file sync, GitHub Release creation, and Docker build automatically.

## Key Files

| File | Purpose |
|------|---------|
| `VERSION` | Single source of truth for app version (e.g. `0.1.15`) |
| `frontend/package.json` | `"version"` field — must match `VERSION` |
| `frontend/public/sw.js` | `CACHE_VERSION` constant — auto-bumped to `selfmx-vX.Y.Z` on release |
| `scripts/bump-version.sh` | Script to bump all version files (`patch`, `minor`, `major`, or exact version) |
| `scripts/push.ps1` | Quick release script — automates commit, version bump, tag, and push |
| `.github/workflows/release.yml` | Release workflow (tag push or workflow_dispatch) |

## Before You Start

1. Ensure working tree is clean or has only the changes you want to commit
2. Check current version: read `VERSION` file
3. Check latest tag: `git tag --sort=-v:refname | Select-Object -First 3` (PowerShell) or `git tag --sort=-v:refname | head -3` (bash)
4. Decide the next version (typically patch bump: `0.1.15` → `0.1.16`)

## Version Bumping Strategy

When releasing, you have two options for handling version bumps:

**Option A: Bump locally (Recommended)**
- Bump version files before committing using `scripts/bump-version.sh`
- Version bump is included in your feature commit (cleaner history)
- When you push the tag, the workflow's sync step detects files are already up to date and skips creating a separate commit
- **Use this approach** for cleaner git history

**Option B: Let workflow bump**
- Don't bump version files locally
- Push your feature commit and tag
- Workflow will create a separate `Release vX.Y.Z` commit updating version files
- **Use this approach** if you forget to bump locally or prefer separate release commits

**Recommendation**: Use Option A (bump locally) for cleaner history. The workflow handles both cases correctly.

## Step 1: Stage and Review Changes

```powershell
# Check what's changed
git status
git diff --stat

# Stage everything
git add -A

# Or stage selectively
git add <specific files>
```

## Step 2: Commit

**CRITICAL — PowerShell does not support heredoc (`<<'EOF'`).** Use one of these approaches:

### Option A: Write message to temp file (recommended for multiline)

Write the commit message to `.git/COMMIT_MSG` using the Write tool, then:

```powershell
git commit -F .git/COMMIT_MSG
```

### Option B: Inline single-line message

```powershell
git commit -m "feat: short description of changes"
```

### Commit Message Format

Follow conventional commits style matching the repo history:

```
feat: short summary of what changed

- Detail 1
- Detail 2
- Detail 3
```

Common prefixes: `feat:`, `fix:`, `chore:`, `docs:`, `refactor:`

## Step 3: Push the Commit

```powershell
git push origin master
```

### If Push is Rejected (Remote Has New Commits)

This commonly happens when a previous release workflow synced version files:

```powershell
# Fetch and rebase
git fetch origin
git pull --rebase origin master
```

**If rebase has conflicts** (typically in `VERSION` and/or `frontend/package.json`):

1. Resolve conflicts — keep the **higher version** or the version you intend to release
2. Stage resolved files: `git add VERSION frontend/package.json`
3. Continue rebase: `$env:GIT_EDITOR = "true"; git rebase --continue`
   - **CRITICAL**: Must set `GIT_EDITOR` because PowerShell terminals are "dumb" — git will fail with `Terminal is dumb, but EDITOR unset` otherwise
   - Must run with `required_permissions: ["all"]` to avoid sandbox permission issues on `.git/` files

Then push again:

```powershell
git push origin master
```

## Step 4: Create and Push the Tag

```powershell
# Create the tag locally
git tag v0.1.16

# Push the commit AND the tag together
git push origin master v0.1.16
```

**IMPORTANT**: Push the commit and tag in the **same command** to avoid the race condition where the tag arrives before the commit (which causes the release workflow to build the wrong code).

If the commit was already pushed in Step 3, just push the tag:

```powershell
git push origin v0.1.16
```

## Step 5: Verify the Release

The tag push triggers the GitHub Actions release workflow which will:

1. Check out `master`
2. Run `scripts/bump-version.sh` to sync `VERSION` and `frontend/package.json`
3. Commit version sync (if files changed) as `Release v0.1.16`
4. Create a GitHub Release with auto-generated release notes
5. Build and push the Docker image to `ghcr.io`

Check the workflow status:

```powershell
gh run list --limit 3
```

Or check on GitHub: `https://github.com/<owner>/<repo>/actions`

## Common Gotchas

### 1. PowerShell Heredoc

PowerShell does **not** support bash-style heredoc (`<<'EOF'`). Use a temp file for multiline commit messages:

```
# WRONG — will fail in PowerShell:
git commit -m "$(cat <<'EOF'
message
EOF
)"

# RIGHT — use a file:
# Write message to .git/COMMIT_MSG with the Write tool
git commit -F .git/COMMIT_MSG
```

### 2. Version File Conflicts After Rebase

The release workflow pushes a `Release vX.Y.Z` commit that updates `VERSION`, `frontend/package.json`, and `frontend/public/sw.js`. If you rebase on top of this, you'll get conflicts in those files. Always resolve to the **version you're about to release**.

### 3. Tag Already Exists on Remote

If you pushed a tag and then needed to rebase (changing the commit SHA), you need to force-update the tag:

```powershell
# Delete remote tag
git push origin :refs/tags/v0.1.16

# Re-create locally
git tag -f v0.1.16

# Push again
git push origin v0.1.16
```

**However**, if the release workflow already ran for that tag, it may have created a GitHub Release and/or Docker image. In that case, it's cleaner to bump to the next patch version instead.

### 4. GIT_EDITOR Not Set

When running `git rebase --continue` in PowerShell, git needs an editor to confirm the commit message. Set the editor to a no-op:

```powershell
$env:GIT_EDITOR = "true"
git rebase --continue
```

### 5. Sandbox Permission Denied on `.git/`

Git rebase operations modify files in `.git/` (like `index.lock`, `rebase-merge/`). If you get "Permission denied" errors, run with `required_permissions: ["all"]`.

### 6. Version Bumping Strategy

Both approaches work correctly:

- **Bump locally**: Use `scripts/bump-version.sh` before committing. This updates `VERSION`, `frontend/package.json`, and `frontend/public/sw.js` (CACHE_VERSION). The workflow's sync step will detect files are already up to date and skip creating a separate commit. This keeps version bumps in your feature commits (cleaner history).

- **Let workflow bump**: Don't bump locally. The workflow will create a separate `Release vX.Y.Z` commit updating all three version files. This is fine but creates an extra commit in history.

**Recommendation**: Bump locally for cleaner git history. See the "Version Bumping Strategy" section above for details.

## Quick Reference — Full Release in One Go

### Option A: Use the release script (recommended)

```powershell
./scripts/push.ps1 patch "feat: short description of changes"
./scripts/push.ps1 minor "feat: add new feature set"
./scripts/push.ps1 major "feat!: breaking change"
```

The script handles everything: stage, commit, version bump (VERSION + package.json + sw.js), release commit, tag, and push. It pushes to the current branch (not hardcoded to master).

### Option B: Manual steps (if you need more control)

```powershell
# 1. Stage all changes
git add -A

# 2. Write commit message (use Write tool for multiline)
# Then commit:
git commit -F .git/COMMIT_MSG

# 3. Bump version using script (read current from VERSION first)
# For patch bump:
bash scripts/bump-version.sh patch
# Or for exact version:
bash scripts/bump-version.sh 0.1.16

# 4. Commit version bump (bump-version.sh also updates sw.js CACHE_VERSION)
git add VERSION frontend/package.json frontend/public/sw.js
git commit -m "Release v0.1.16"

# 5. Tag
git tag v0.1.16

# 6. Push everything
git push origin master v0.1.16
```

## Checklist

- [ ] All changes staged and committed with descriptive message
- [ ] No debug code, secrets, or console.log in committed files
- [ ] Version bumped in `VERSION`, `frontend/package.json`, and `frontend/public/sw.js`
- [ ] Tag created matching the version (`vX.Y.Z`)
- [ ] Commit and tag pushed to remote
- [ ] Release workflow triggered (check GitHub Actions)
- [ ] `git status` shows clean working tree, up to date with remote
