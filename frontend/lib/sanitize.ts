import DOMPurify from "dompurify";

/**
 * Sanitize custom CSS to remove potentially dangerous patterns.
 * Strips @import, expression(), behavior:, -moz-binding, javascript: URIs,
 * and url() with http/https schemes (allows data: and relative URLs).
 */
export function sanitizeCss(css: string): string {
  if (!css) return "";

  let sanitized = css;

  // Remove @import rules (external stylesheet loading / data exfiltration)
  sanitized = sanitized.replace(/@import\b[^;]*;?/gi, "/* removed */");

  // Remove expression() (IE CSS expressions — executes JS)
  sanitized = sanitized.replace(/expression\s*\([^)]*\)/gi, "/* removed */");

  // Remove behavior: (IE .htc files — executes script)
  sanitized = sanitized.replace(/behavior\s*:\s*[^;}"']*/gi, "/* removed */");

  // Remove -moz-binding (Firefox XBL — executes script)
  sanitized = sanitized.replace(/-moz-binding\s*:\s*[^;}"']*/gi, "/* removed */");

  // Remove javascript: in url()
  sanitized = sanitized.replace(
    /url\s*\(\s*['"]?\s*javascript\s*:/gi,
    "url(/* removed */",
  );

  // Remove url() with http/https schemes (external resource loading)
  sanitized = sanitized.replace(
    /url\s*\(\s*['"]?\s*https?:\/\/[^)]*\)/gi,
    "/* removed */",
  );

  return sanitized;
}

/**
 * Sanitize HTML to only allow safe formatting tags (for search highlights, etc.)
 */
export function sanitizeHighlightHtml(html: string): string {
  if (typeof window === "undefined") return "";
  return DOMPurify.sanitize(html, {
    ALLOWED_TAGS: ["em", "mark", "strong", "b"],
  });
}

/**
 * Sanitize rich email HTML content (for quoted replies, forwarded messages, etc.)
 */
export function sanitizeEmailHtml(html: string): string {
  if (typeof window === "undefined") return "";
  return DOMPurify.sanitize(html, {
    ALLOWED_TAGS: [
      "p", "br", "strong", "em", "b", "i", "u", "a",
      "ul", "ol", "li", "blockquote", "div", "span",
      "h1", "h2", "h3", "h4", "h5", "h6", "img",
      "table", "thead", "tbody", "tr", "td", "th",
    ],
    ALLOWED_ATTR: ["href", "src", "alt", "class", "target", "rel"],
  });
}
