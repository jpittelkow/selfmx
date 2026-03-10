import { type ReactNode, useEffect, useState, useCallback, useRef } from "react";

export interface TocHeading {
  level: number;
  text: string;
  slug: string;
}

/**
 * Generate a URL-safe slug from text.
 */
export function slugify(text: string): string {
  return text
    .toLowerCase()
    .replace(/[^\w\s-]/g, "")
    .replace(/\s+/g, "-")
    .replace(/-+/g, "-")
    .replace(/^-|-$/g, "");
}

/**
 * Strip common inline markdown formatting from text.
 * Handles: `code`, **bold**, *italic*, [link text](url)
 */
function stripInlineMarkdown(text: string): string {
  return text
    .replace(/\[([^\]]+)\]\([^)]+\)/g, "$1") // [text](url) -> text
    .replace(/`([^`]+)`/g, "$1")              // `code` -> code
    .replace(/\*\*([^*]+)\*\*/g, "$1")        // **bold** -> bold
    .replace(/\*([^*]+)\*/g, "$1")            // *italic* -> italic
    .replace(/__([^_]+)__/g, "$1")            // __bold__ -> bold
    .replace(/_([^_]+)_/g, "$1");             // _italic_ -> italic
}

/**
 * Recursively extract plain text from React children nodes.
 */
export function childrenToText(children: ReactNode): string {
  if (typeof children === "string") return children;
  if (typeof children === "number") return String(children);
  if (!children) return "";
  if (Array.isArray(children)) return children.map(childrenToText).join("");
  if (typeof children === "object" && "props" in children) {
    return childrenToText((children as { props: { children?: ReactNode } }).props.children);
  }
  return "";
}

/**
 * Resolve the Radix ScrollArea viewport element from a container ref.
 * Falls back to the container itself if the viewport is not found.
 */
export function getRadixViewport(container: HTMLElement): HTMLElement {
  return container.querySelector<HTMLElement>(
    "[data-radix-scroll-area-viewport]"
  ) ?? container;
}

/**
 * Extract headings from a markdown string.
 * Returns null if fewer than 3 headings are found.
 * Slugs match what makeHeading() produces in help-article.tsx — bare slugs
 * without duplicate counters, since React's childrenToText produces the same
 * plain text for each heading occurrence.
 */
export function extractHeadings(markdown: string): TocHeading[] | null {
  const headingRegex = /^(#{1,3})\s+(.+)$/gm;
  const headings: TocHeading[] = [];

  let match;
  while ((match = headingRegex.exec(markdown)) !== null) {
    const level = match[1].length;
    const rawText = match[2].trim();
    const text = stripInlineMarkdown(rawText);
    const slug = slugify(text);

    headings.push({ level, text, slug });
  }

  return headings.length >= 3 ? headings : null;
}

/**
 * Hook that tracks which heading is currently active based on scroll position.
 * Uses IntersectionObserver on the Radix ScrollArea viewport.
 */
export function useActiveHeading(
  headings: TocHeading[] | null,
  scrollContainerRef: React.RefObject<HTMLElement | null>
): string | null {
  const [activeSlug, setActiveSlug] = useState<string | null>(null);
  // Track all currently visible heading IDs to pick the topmost on each change
  const visibleSlugsRef = useRef(new Set<string>());

  const getViewport = useCallback(() => {
    const container = scrollContainerRef.current;
    if (!container) return null;
    return getRadixViewport(container);
  }, [scrollContainerRef]);

  useEffect(() => {
    if (!headings || headings.length === 0) return;

    const viewport = getViewport();
    if (!viewport) return;

    const headingElements = headings
      .map((h) => viewport.querySelector<HTMLElement>(`#${CSS.escape(h.slug)}`))
      .filter(Boolean) as HTMLElement[];

    if (headingElements.length === 0) return;

    // Reset visible set and set initial active to first heading
    visibleSlugsRef.current.clear();
    setActiveSlug(headings[0].slug);

    const observer = new IntersectionObserver(
      (entries) => {
        const visibleSet = visibleSlugsRef.current;

        for (const entry of entries) {
          if (entry.isIntersecting) {
            visibleSet.add(entry.target.id);
          } else {
            visibleSet.delete(entry.target.id);
          }
        }

        // Pick the topmost visible heading by DOM order
        if (visibleSet.size > 0) {
          for (const h of headings) {
            if (visibleSet.has(h.slug)) {
              setActiveSlug(h.slug);
              break;
            }
          }
        }
      },
      {
        root: viewport,
        rootMargin: "0px 0px -70% 0px",
        threshold: 0,
      }
    );

    headingElements.forEach((el) => observer.observe(el));
    return () => observer.disconnect();
  }, [headings, getViewport]);

  return activeSlug;
}
