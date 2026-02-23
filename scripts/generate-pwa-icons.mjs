#!/usr/bin/env node
/**
 * Generate PWA PNG icons from icon.svg.
 * Run from project root: node scripts/generate-pwa-icons.mjs
 * Or from frontend: npm run generate-icons
 * Requires: npm install sharp --save-dev (in frontend)
 *
 * Outputs:
 *   frontend/public/icons/       - PWA icon set (48–512px) and shortcut icons (96px)
 *   frontend/public/icon-192.png - Backward compat (referenced by sw.js push)
 *   frontend/public/icon-512.png - Backward compat
 *   frontend/public/apple-icon.png - 180x180 Apple touch icon (iOS Add to Home Screen)
 *   frontend/public/favicon.ico    - 32x32 PNG favicon (older browsers)
 *   frontend/public/badge.png      - 96x96 notification badge (Android)
 */

import { readFileSync, writeFileSync, mkdirSync, existsSync } from 'fs';
import { dirname, join } from 'path';
import { fileURLToPath } from 'url';
import { createRequire } from 'module';

const __dirname = dirname(fileURLToPath(import.meta.url));
const rootDir = join(__dirname, '..');
const frontendDir = join(rootDir, 'frontend');
const publicDir = join(frontendDir, 'public');
const iconsDir = join(publicDir, 'icons');
const svgPath = join(publicDir, 'icon.svg');

const require = createRequire(join(frontendDir, 'package.json'));

const MAIN_SIZES = [48, 72, 96, 128, 144, 152, 192, 384, 512];
const SHORTCUT_SIZE = 96;
const APPLE_ICON_SIZE = 180;
const FAVICON_SIZE = 32;
const BADGE_SIZE = 96;

/**
 * Build a minimal ICO file wrapping a single PNG image.
 * ICO format: 6-byte header + 16-byte directory entry + raw PNG data.
 * Modern browsers accept PNG-in-ICO which avoids BMP conversion.
 */
function pngToIco(pngBuffer, width, height) {
  const dir = Buffer.alloc(16);
  dir.writeUInt8(width < 256 ? width : 0, 0);
  dir.writeUInt8(height < 256 ? height : 0, 1);
  dir.writeUInt8(0, 2);  // color palette
  dir.writeUInt8(0, 3);  // reserved
  dir.writeUInt16LE(1, 4); // color planes
  dir.writeUInt16LE(32, 6); // bits per pixel
  dir.writeUInt32LE(pngBuffer.length, 8); // image size
  dir.writeUInt32LE(6 + 16, 12); // offset to image data

  const header = Buffer.alloc(6);
  header.writeUInt16LE(0, 0); // reserved
  header.writeUInt16LE(1, 2); // ICO type
  header.writeUInt16LE(1, 4); // 1 image

  return Buffer.concat([header, dir, pngBuffer]);
}

async function generateIcons() {
  let sharp;
  try {
    sharp = require('sharp');
  } catch {
    console.error('sharp is required. Run: cd frontend && npm install sharp --save-dev');
    process.exit(1);
  }

  if (!existsSync(svgPath)) {
    console.error(`Missing ${svgPath}`);
    process.exit(1);
  }

  if (!existsSync(iconsDir)) {
    mkdirSync(iconsDir, { recursive: true });
  }

  const svg = readFileSync(svgPath);

  // Main PWA icon set
  for (const size of MAIN_SIZES) {
    const outputPath = join(iconsDir, `icon-${size}.png`);
    await sharp(svg)
      .resize(size, size)
      .png()
      .toFile(outputPath);
    console.log(`Generated ${outputPath}`);
  }

  // Shortcut icons
  for (const name of ['shortcut-dashboard', 'shortcut-settings']) {
    const outputPath = join(iconsDir, `${name}.png`);
    await sharp(svg)
      .resize(SHORTCUT_SIZE, SHORTCUT_SIZE)
      .png()
      .toFile(outputPath);
    console.log(`Generated ${outputPath}`);
  }

  // Backward-compat root icons (referenced by sw.js push notification icon)
  await sharp(svg)
    .resize(192, 192)
    .png()
    .toFile(join(publicDir, 'icon-192.png'));
  await sharp(svg)
    .resize(512, 512)
    .png()
    .toFile(join(publicDir, 'icon-512.png'));
  console.log('Generated public/icon-192.png and public/icon-512.png (backward compatibility)');

  // Apple touch icon (180x180 for iOS Add to Home Screen)
  const appleIconPath = join(publicDir, 'apple-icon.png');
  await sharp(svg)
    .resize(APPLE_ICON_SIZE, APPLE_ICON_SIZE)
    .png()
    .toFile(appleIconPath);
  console.log(`Generated ${appleIconPath}`);

  // favicon.ico (32x32 PNG wrapped in ICO container, from purpose-built favicon.svg)
  const faviconSvgPath = join(publicDir, 'favicon.svg');
  const faviconSvg = existsSync(faviconSvgPath) ? readFileSync(faviconSvgPath) : svg;
  const faviconPng = await sharp(faviconSvg)
    .resize(FAVICON_SIZE, FAVICON_SIZE)
    .png()
    .toBuffer();
  const icoBuffer = pngToIco(faviconPng, FAVICON_SIZE, FAVICON_SIZE);
  const faviconPath = join(publicDir, 'favicon.ico');
  writeFileSync(faviconPath, icoBuffer);
  console.log(`Generated ${faviconPath}`);

  // Notification badge (96x96 for Android notification tray)
  const badgePath = join(publicDir, 'badge.png');
  await sharp(svg)
    .resize(BADGE_SIZE, BADGE_SIZE)
    .png()
    .toFile(badgePath);
  console.log(`Generated ${badgePath}`);

  console.log('Done.');
}

generateIcons().catch((err) => {
  console.error(err);
  process.exit(1);
});
