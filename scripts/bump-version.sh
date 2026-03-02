#!/usr/bin/env bash
# Bump application version across VERSION, frontend/package.json, and frontend/public/sw.js.
# Usage: ./scripts/bump-version.sh patch|minor|major|1.2.3
# Output: prints the new version to stdout (for GITHUB_OUTPUT).

set -e

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
ROOT_DIR="$(cd "$SCRIPT_DIR/.." && pwd)"
VERSION_FILE="$ROOT_DIR/VERSION"
PACKAGE_JSON="$ROOT_DIR/frontend/package.json"
SW_JS="$ROOT_DIR/frontend/public/sw.js"

if [ ! -f "$VERSION_FILE" ]; then
  echo "Error: VERSION file not found at $VERSION_FILE" >&2
  exit 1
fi

if [ ! -f "$PACKAGE_JSON" ]; then
  echo "Error: frontend/package.json not found at $PACKAGE_JSON" >&2
  exit 1
fi

if [ -z "$1" ]; then
  echo "Usage: $0 patch|minor|major|<x.y.z>" >&2
  exit 1
fi

CURRENT=$(cat "$VERSION_FILE" | tr -d '\n\r ')
IFS='.' read -r MAJOR MINOR PATCH <<< "$CURRENT"
MAJOR=${MAJOR:-0}
MINOR=${MINOR:-0}
PATCH=${PATCH:-0}

case "$1" in
  patch)
    NEW_VERSION="$MAJOR.$MINOR.$((PATCH + 1))"
    ;;
  minor)
    NEW_VERSION="$MAJOR.$((MINOR + 1)).0"
    ;;
  major)
    NEW_VERSION="$((MAJOR + 1)).0.0"
    ;;
  *)
    if [[ "$1" =~ ^[0-9]+\.[0-9]+\.[0-9]+(-[a-zA-Z0-9.]+)?(\+[a-zA-Z0-9.]+)?$ ]]; then
      NEW_VERSION="$1"
    else
      echo "Error: invalid version type or semver: $1" >&2
      exit 1
    fi
    ;;
esac

echo "$NEW_VERSION" > "$VERSION_FILE"

if sed --version 2>/dev/null | grep -q GNU; then
  sed -i "s/\"version\": *\"[^\"]*\"/\"version\": \"$NEW_VERSION\"/" "$PACKAGE_JSON"
else
  sed -i '' "s/\"version\": *\"[^\"]*\"/\"version\": \"$NEW_VERSION\"/" "$PACKAGE_JSON"
fi

# Update CACHE_VERSION in service worker so caches bust on release
if [ -f "$SW_JS" ]; then
  if sed --version 2>/dev/null | grep -q GNU; then
    sed -i "s/const CACHE_VERSION = 'selfmx-v[^']*'/const CACHE_VERSION = 'selfmx-v$NEW_VERSION'/" "$SW_JS"
  else
    sed -i '' "s/const CACHE_VERSION = 'selfmx-v[^']*'/const CACHE_VERSION = 'selfmx-v$NEW_VERSION'/" "$SW_JS"
  fi
fi

echo "$NEW_VERSION"
