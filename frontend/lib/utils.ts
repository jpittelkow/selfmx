import { type ClassValue, clsx } from "clsx";
import { twMerge } from "tailwind-merge";

export function cn(...inputs: ClassValue[]) {
  return twMerge(clsx(inputs));
}

// ---------------------------------------------------------------------------
// User timezone (set by auth flow, used by date formatters)
// ---------------------------------------------------------------------------

let _userTimezone: string | undefined;

/**
 * Set the user's effective timezone. Called from the auth store
 * when the user data loads (GET /auth/user includes timezone).
 * Pass undefined to clear (e.g. on logout).
 */
export function setUserTimezone(tz: string | undefined): void {
  _userTimezone = tz;
}

/**
 * Get the user's effective timezone, or undefined to use browser default.
 */
export function getUserTimezone(): string | undefined {
  return _userTimezone;
}

// ---------------------------------------------------------------------------
// Formatting utilities
// ---------------------------------------------------------------------------

/**
 * Format byte count as human-readable string (B, KB, MB, GB, TB).
 */
export function formatBytes(bytes: number, decimals = 2): string {
  if (typeof bytes !== "number" || !Number.isFinite(bytes) || bytes < 0) return "0 B";
  if (bytes === 0) return "0 B";
  const k = 1024;
  const sizes = ["B", "KB", "MB", "GB", "TB"];
  const i = Math.floor(Math.log(bytes) / Math.log(k));
  return parseFloat((bytes / Math.pow(k, i)).toFixed(decimals)) + " " + sizes[i];
}

/**
 * Format date string or Date for display. Default: "Jan 1, 2026".
 * Uses the user's timezone when available.
 * Returns the original input if the date is invalid.
 */
export function formatDate(
  date: string | Date,
  options?: Intl.DateTimeFormatOptions
): string {
  try {
    const d = new Date(date);
    if (Number.isNaN(d.getTime())) return typeof date === "string" ? date : String(date);
    const tz = _userTimezone;
    const baseOpts: Intl.DateTimeFormatOptions = options ?? {
      year: "numeric",
      month: "short",
      day: "numeric",
    };
    const opts: Intl.DateTimeFormatOptions = tz && !baseOpts.timeZone
      ? { ...baseOpts, timeZone: tz }
      : baseOpts;
    return d.toLocaleDateString(undefined, opts);
  } catch {
    return typeof date === "string" ? date : String(date);
  }
}

/**
 * Format date with time (e.g. for backup created_at).
 * Uses the user's timezone when available.
 * Returns the original input if the date is invalid.
 */
export function formatDateTime(date: string | Date): string {
  try {
    const d = new Date(date);
    if (Number.isNaN(d.getTime())) return typeof date === "string" ? date : String(date);
    const tz = _userTimezone;
    const opts: Intl.DateTimeFormatOptions = tz ? { timeZone: tz } : {};
    return d.toLocaleString(undefined, opts);
  } catch {
    return typeof date === "string" ? date : String(date);
  }
}

/**
 * Format Unix timestamp (seconds) for display. Returns "---" for null/undefined or invalid numbers.
 * Uses the user's timezone when available.
 */
export function formatTimestamp(ts: number | null | undefined): string {
  if (ts === null || ts === undefined || typeof ts !== "number" || Number.isNaN(ts)) return "\u2014";
  const d = new Date(ts * 1000);
  if (Number.isNaN(d.getTime())) return "\u2014";
  const tz = _userTimezone;
  const opts: Intl.DateTimeFormatOptions = tz ? { timeZone: tz } : {};
  return d.toLocaleString(undefined, opts);
}

/**
 * Extract user-facing message from caught error (Error, Axios-style response, or unknown).
 */
export function getErrorMessage(error: unknown, fallback: string): string {
  if (error instanceof Error) return error.message;
  if (error && typeof error === "object" && "response" in error) {
    const r = (error as { response?: { data?: { message?: string; error?: string } } }).response?.data;
    return r?.message ?? r?.error ?? fallback;
  }
  return fallback;
}

/**
 * Format a number as USD currency (e.g. $42.50).
 */
export function formatCurrency(value: number): string {
  if (!Number.isFinite(value)) return "$0.00";
  return `$${value.toFixed(2)}`;
}

/**
 * Get initials from a full name (up to 2 characters).
 */
export function getInitials(name: string): string {
  if (!name?.trim()) return "?";
  return name
    .split(" ")
    .map((n) => n[0])
    .join("")
    .toUpperCase()
    .slice(0, 2);
}
