"use client";

import { useCallback } from "react";
import { List } from "lucide-react";
import { cn } from "@/lib/utils";
import { Button } from "@/components/ui/button";
import {
  Collapsible,
  CollapsibleContent,
  CollapsibleTrigger,
} from "@/components/ui/collapsible";
import { type TocHeading, useActiveHeading, getRadixViewport } from "@/lib/help/help-toc";

interface HelpArticleTocProps {
  headings: TocHeading[];
  scrollContainerRef: React.RefObject<HTMLElement | null>;
  variant: "sidebar" | "inline";
}

export function HelpArticleToc({
  headings,
  scrollContainerRef,
  variant,
}: HelpArticleTocProps) {
  const activeSlug = useActiveHeading(headings, scrollContainerRef);

  const scrollToHeading = useCallback(
    (slug: string) => {
      const container = scrollContainerRef.current;
      if (!container) return;
      const viewport = getRadixViewport(container);
      const el = viewport.querySelector<HTMLElement>(`#${CSS.escape(slug)}`);
      if (!el) return;
      // Calculate offset relative to the scroll container
      const containerRect = viewport.getBoundingClientRect();
      const elRect = el.getBoundingClientRect();
      const offset = elRect.top - containerRect.top + viewport.scrollTop - 16;
      viewport.scrollTo({ top: offset, behavior: "smooth" });
    },
    [scrollContainerRef]
  );

  const tocList = (
    <nav aria-label="Table of contents">
      <ul className="space-y-0.5">
        {headings.map((heading, index) => (
          <li key={`${heading.slug}-${index}`}>
            <button
              type="button"
              onClick={() => scrollToHeading(heading.slug)}
              className={cn(
                "text-left text-xs w-full py-1 border-l-2 -ml-px transition-colors hover:text-foreground",
                heading.level === 1 && "pl-2",
                heading.level === 2 && "pl-5",
                heading.level === 3 && "pl-8",
                activeSlug === heading.slug
                  ? "text-primary font-medium border-primary"
                  : "text-muted-foreground border-transparent"
              )}
            >
              {heading.text}
            </button>
          </li>
        ))}
      </ul>
    </nav>
  );

  if (variant === "inline") {
    return (
      <Collapsible>
        <CollapsibleTrigger asChild>
          <Button
            variant="ghost"
            size="sm"
            className="w-full justify-start gap-2 text-muted-foreground h-8 mb-2"
          >
            <List className="h-3.5 w-3.5" />
            <span className="text-xs font-medium">On this page</span>
          </Button>
        </CollapsibleTrigger>
        <CollapsibleContent className="pb-4 border-b mb-4">
          {tocList}
        </CollapsibleContent>
      </Collapsible>
    );
  }

  return (
    <div>
      <p className="text-xs font-medium text-muted-foreground mb-3">
        On this page
      </p>
      {tocList}
    </div>
  );
}
