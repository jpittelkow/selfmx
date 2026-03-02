/**
 * Centralized font configuration.
 *
 * === CHANGE YOUR FONTS HERE ===
 *
 * Browse Google Fonts: https://fonts.google.com
 * Import any font from "next/font/google" and assign it below.
 *
 * Popular pairings (body + heading):
 *   Inter + Newsreader         — clean body, editorial headings (default)
 *   DM Sans + DM Serif Display — geometric, modern
 *   Plus Jakarta Sans + Lora   — friendly, warm
 *   Geist Sans + Geist Mono    — Vercel-style, techy
 *   Source Sans 3 + Playfair Display — corporate, classic
 *   Poppins + Abril Fatface    — creative, bold
 *   Nunito + Merriweather      — soft, readable
 *
 * After changing fonts:
 *   1. Update the import statement to match the new font
 *   2. Adjust weight arrays as needed (check Google Fonts for available weights)
 *   3. The CSS variable names (--font-body, --font-heading) stay the same
 *   4. Tailwind config and globals.css reference these variables automatically
 */

import { DM_Sans, DM_Serif_Display } from "next/font/google";

/** Body font — used for all body text, UI elements, and labels */
export const bodyFont = DM_Sans({
  subsets: ["latin"],
  variable: "--font-body",
});

/** Heading font — used for h1-h6 elements */
export const headingFont = DM_Serif_Display({
  subsets: ["latin"],
  weight: ["400"],
  variable: "--font-heading",
  adjustFontFallback: false,
});
