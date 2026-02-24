#!/usr/bin/env pwsh
# Quick release script - commits all changes, bumps version, tags, and pushes
# Usage: ./scripts/push.ps1 [patch|minor|major|<version>] [commit-message]
# Example: ./scripts/push.ps1 patch "feat: add new feature"

param(
    [Parameter(Position=0)]
    [string]$VersionBump = "patch",
    
    [Parameter(Position=1)]
    [string]$CommitMessage = ""
)

$ErrorActionPreference = "Stop"

# Get script directory
$ScriptDir = Split-Path -Parent $MyInvocation.MyCommand.Path
$RootDir = Split-Path -Parent $ScriptDir
$VersionFile = Join-Path $RootDir "VERSION"
$ChangelogFile = Join-Path $RootDir "CHANGELOG.md"
$PackageJson = Join-Path (Join-Path $RootDir "frontend") "package.json"
$SwJs = Join-Path (Join-Path (Join-Path $RootDir "frontend") "public") "sw.js"

# Check if we're in a git repository
if (-not (Test-Path (Join-Path $RootDir ".git"))) {
    Write-Error "Not in a git repository. Run this script from the project root."
    exit 1
}

# Check current branch
$CurrentBranch = git rev-parse --abbrev-ref HEAD
if ($CurrentBranch -eq "HEAD") {
    Write-Error "You are in detached HEAD state. Check out a branch before releasing."
    exit 1
}
if ($CurrentBranch -ne "master") {
    Write-Warning "You are on branch '$CurrentBranch', not 'master'. Continue anyway? (y/N)"
    $Response = Read-Host
    if ($Response -ne "y" -and $Response -ne "Y") {
        Write-Host "Aborted." -ForegroundColor Yellow
        exit 0
    }
}

# Check for uncommitted changes
$status = git status --porcelain
if ([string]::IsNullOrWhiteSpace($status)) {
    Write-Host "No changes to commit. Working tree is clean." -ForegroundColor Yellow
    exit 0
}

# Show what will be committed
Write-Host "`nChanges to be committed:" -ForegroundColor Cyan
git status --short

# Get commit message if not provided
if ([string]::IsNullOrWhiteSpace($CommitMessage)) {
    if ([Environment]::UserInteractive -and (-not [Console]::IsInputRedirected)) {
        Write-Host "`nEnter commit message (or press Enter for auto-generated):" -ForegroundColor Yellow
        $CommitMessage = Read-Host
    }
    if ([string]::IsNullOrWhiteSpace($CommitMessage)) {
        $ChangedFiles = @(git status --porcelain | ForEach-Object { $_.Substring(3).Trim() })
        $FileCount = $ChangedFiles.Count
        if ($FileCount -gt 0) {
            $Summary = ($ChangedFiles | Select-Object -First 5) -join ", "
            if ($FileCount -gt 5) { $Summary += " (+$($FileCount - 5) more)" }
            $CommitMessage = "chore: update $FileCount file(s) -- $Summary"
        } else {
            $CommitMessage = "chore: update files"
        }
    }
}

# Stage all changes
Write-Host "`nStaging all changes..." -ForegroundColor Cyan
git add -A

# Commit
Write-Host "Committing changes..." -ForegroundColor Cyan
git commit -m "$CommitMessage"

# Run tests before pushing
Write-Host "`n========================================" -ForegroundColor Magenta
Write-Host "Running tests before release..." -ForegroundColor Magenta
Write-Host "========================================" -ForegroundColor Magenta

# Run backend tests
Write-Host "`nRunning backend tests in Docker..." -ForegroundColor Yellow
docker compose exec -T app bash -c "cd /var/www/html/backend && php artisan test" 2>&1
$BackendTestExit = $LASTEXITCODE

if ($BackendTestExit -ne 0) {
    Write-Host "`nBackend tests failed!" -ForegroundColor Red
    Write-Host "Fix the test failures and try again." -ForegroundColor Red
    # Reset the commits we made
    Write-Host "Resetting commits..." -ForegroundColor Yellow
    git reset --soft HEAD~1
    exit 1
}

Write-Host "Backend tests passed!" -ForegroundColor Green

# Run frontend tests
Write-Host "`nRunning frontend tests in Docker..." -ForegroundColor Yellow
docker compose exec -T app bash -c "cd /var/www/html/frontend && npm test" 2>&1
$FrontendTestExit = $LASTEXITCODE

if ($FrontendTestExit -ne 0) {
    Write-Host "`nFrontend tests failed!" -ForegroundColor Red
    Write-Host "Fix the test failures and try again." -ForegroundColor Red
    # Reset the commits we made
    Write-Host "Resetting commits..." -ForegroundColor Yellow
    git reset --soft HEAD~1
    exit 1
}

Write-Host "Frontend tests passed!" -ForegroundColor Green

Write-Host "`n========================================" -ForegroundColor Green
Write-Host "All tests passed! Proceeding with release..." -ForegroundColor Green
Write-Host "========================================" -ForegroundColor Green

# Read current version
$CurrentVersion = (Get-Content $VersionFile).Trim()
Write-Host "`nCurrent version: $CurrentVersion" -ForegroundColor Cyan

# Calculate new version
$VersionParts = $CurrentVersion -split '\.'
$Major = [int]$VersionParts[0]
$Minor = [int]$VersionParts[1]
$Patch = [int]$VersionParts[2]

$NewVersion = switch ($VersionBump.ToLower()) {
    "patch" { "$Major.$Minor.$($Patch + 1)" }
    "minor" { "$Major.$($Minor + 1).0" }
    "major" { "$($Major + 1).0.0" }
    default {
        # Check if it's a valid semver
        if ($VersionBump -match '^\d+\.\d+\.\d+(-[a-zA-Z0-9.]+)?(\+[a-zA-Z0-9.]+)?$') {
            $VersionBump
        } else {
            Write-Error "Invalid version bump: $VersionBump. Use patch, minor, major, or x.y.z"
            exit 1
        }
    }
}

Write-Host "New version: $NewVersion" -ForegroundColor Green

# Auto-update CHANGELOG.md with commits since last tag
Write-Host "Updating changelog..." -ForegroundColor Cyan
$LastTag = git describe --tags --abbrev=0 2>$null
if ($LastTag) {
    $CommitRange = "$LastTag..HEAD"
} else {
    $CommitRange = "HEAD"
}
$Commits = @(git log $CommitRange --pretty=format:"%s" --no-merges 2>$null)

if ($Commits.Count -gt 0) {
    $Added = @()
    $Changed = @()
    $Fixed = @()
    $Removed = @()
    $Security = @()
    $Other = @()

    foreach ($msg in $Commits) {
        # Strip conventional commit prefix to get the description
        $desc = $msg -replace '^[a-z]+(\(.+?\))?:\s*', ''
        # Capitalize first letter
        if ($desc.Length -gt 0) {
            $desc = $desc.Substring(0,1).ToUpper() + $desc.Substring(1)
        }

        switch -Regex ($msg) {
            '^feat'     { $Added += $desc; break }
            '^fix'      { $Fixed += $desc; break }
            '^security' { $Security += $desc; break }
            '^remove'   { $Removed += $desc; break }
            '^refactor' { $Changed += $desc; break }
            '^chore'    { $Changed += $desc; break }
            default     { $Other += $desc; break }
        }
    }

    # Build the new changelog entry
    $Today = Get-Date -Format "yyyy-MM-dd"
    $Entry = "`n## [$NewVersion] - $Today`n"

    if ($Added.Count -gt 0) {
        $Entry += "`n### Added`n"
        foreach ($item in $Added) { $Entry += "- $item`n" }
    }
    if ($Changed.Count -gt 0) {
        $Entry += "`n### Changed`n"
        foreach ($item in $Changed) { $Entry += "- $item`n" }
    }
    if ($Fixed.Count -gt 0) {
        $Entry += "`n### Fixed`n"
        foreach ($item in $Fixed) { $Entry += "- $item`n" }
    }
    if ($Removed.Count -gt 0) {
        $Entry += "`n### Removed`n"
        foreach ($item in $Removed) { $Entry += "- $item`n" }
    }
    if ($Security.Count -gt 0) {
        $Entry += "`n### Security`n"
        foreach ($item in $Security) { $Entry += "- $item`n" }
    }
    if ($Other.Count -gt 0) {
        $Entry += "`n### Changed`n"
        foreach ($item in $Other) { $Entry += "- $item`n" }
    }

    # Insert the new entry after the header block in CHANGELOG.md
    if (Test-Path $ChangelogFile) {
        $ChangelogContent = Get-Content $ChangelogFile -Raw
        # Insert after the header (first blank line before first ## entry)
        $HeaderPattern = '(?s)(^# Changelog.*?adheres to \[Semantic Versioning\]\([^)]+\)\.\s*\n)'
        if ($ChangelogContent -match $HeaderPattern) {
            $ChangelogContent = $ChangelogContent -replace $HeaderPattern, "`$1$Entry"
        } else {
            # Fallback: insert after first line
            $ChangelogContent = $ChangelogContent -replace '(^# Changelog\s*\n)', "`$1$Entry"
        }
        Set-Content -Path $ChangelogFile -Value $ChangelogContent -NoNewline
        Write-Host "Added changelog entry for v$NewVersion ($($Commits.Count) commits)" -ForegroundColor Green
    }
} else {
    Write-Host "No commits found since last tag, skipping changelog update" -ForegroundColor Yellow
}

# Update VERSION file
Set-Content -Path $VersionFile -Value $NewVersion -NoNewline

# Update package.json
$PackageContent = Get-Content $PackageJson -Raw
$OldPattern = '"version":\s*"[^"]*"'
$NewPattern = '"version": "' + $NewVersion + '"'
$PackageContent = $PackageContent -replace $OldPattern, $NewPattern
Set-Content -Path $PackageJson -Value $PackageContent -NoNewline

# Update CACHE_VERSION in service worker so caches bust on release
if (Test-Path $SwJs) {
    $SwContent = Get-Content $SwJs -Raw
    $SwOldPattern = "const CACHE_VERSION = 'sourdough-v[^']*'"
    $SwNewPattern = "const CACHE_VERSION = 'sourdough-v$NewVersion'"
    $SwContent = $SwContent -replace $SwOldPattern, $SwNewPattern
    Set-Content -Path $SwJs -Value $SwContent -NoNewline
}

Write-Host "Updated version files" -ForegroundColor Cyan

# Stage version files and changelog
$FilesToStage = @($VersionFile, $PackageJson, $ChangelogFile)
if (Test-Path $SwJs) { $FilesToStage += $SwJs }
git add @FilesToStage

# Commit version bump
Write-Host "Committing version bump..." -ForegroundColor Cyan
git commit -m "Release v$NewVersion"

# Create tag
Write-Host "Creating tag v$NewVersion..." -ForegroundColor Cyan
git tag "v$NewVersion"

# Push everything (commit + tag together to avoid race conditions)
Write-Host "`nPushing to origin..." -ForegroundColor Cyan
git push origin $CurrentBranch "v$NewVersion"

Write-Host ""
Write-Host "Release complete!" -ForegroundColor Green
Write-Host "Version: $NewVersion" -ForegroundColor Green
Write-Host "Tag: v$NewVersion" -ForegroundColor Green
Write-Host ""
Write-Host "GitHub Actions release workflow should now be running." -ForegroundColor Cyan
