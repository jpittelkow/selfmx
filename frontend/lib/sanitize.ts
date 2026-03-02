import DOMPurify from "dompurify";

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
