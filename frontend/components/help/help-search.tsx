"use client";

import { useState, useEffect, useRef, useCallback } from "react";
import { Search, X } from "lucide-react";
import { Input } from "@/components/ui/input";
import { Button } from "@/components/ui/button";
import { cn } from "@/lib/utils";
import { searchHelp, type HelpSearchResult } from "@/lib/help/help-search";

interface HelpSearchProps {
  onSelectResult: (articleId: string) => void;
  className?: string;
}

function highlightMatch(text: string, indices: readonly [number, number][]): React.ReactNode {
  if (!indices.length) return text;

  const parts: React.ReactNode[] = [];
  let lastIndex = 0;

  // Sort and merge overlapping indices
  const sorted = [...indices].sort((a, b) => a[0] - b[0]);
  const merged: [number, number][] = [];
  for (const [start, end] of sorted) {
    const last = merged[merged.length - 1];
    if (last && start <= last[1] + 1) {
      last[1] = Math.max(last[1], end);
    } else {
      merged.push([start, end]);
    }
  }

  for (const [start, end] of merged) {
    if (start > lastIndex) {
      parts.push(text.slice(lastIndex, start));
    }
    parts.push(
      <mark key={start} className="bg-yellow-200/60 dark:bg-yellow-500/30 rounded-sm px-0.5">
        {text.slice(start, end + 1)}
      </mark>
    );
    lastIndex = end + 1;
  }

  if (lastIndex < text.length) {
    parts.push(text.slice(lastIndex));
  }

  return parts;
}

export function HelpSearch({ onSelectResult, className }: HelpSearchProps) {
  const [query, setQuery] = useState("");
  const [results, setResults] = useState<HelpSearchResult[]>([]);
  const [isOpen, setIsOpen] = useState(false);
  const [activeIndex, setActiveIndex] = useState(-1);
  const inputRef = useRef<HTMLInputElement>(null);
  const containerRef = useRef<HTMLDivElement>(null);
  const listRef = useRef<HTMLDivElement>(null);

  useEffect(() => {
    if (query.trim().length >= 2) {
      const searchResults = searchHelp(query);
      setResults(searchResults.slice(0, 5));
      setIsOpen(true);
      setActiveIndex(-1);
    } else {
      setResults([]);
      setIsOpen(false);
      setActiveIndex(-1);
    }
  }, [query]);

  // Close dropdown when clicking outside
  useEffect(() => {
    const handleClickOutside = (e: MouseEvent) => {
      if (
        containerRef.current &&
        !containerRef.current.contains(e.target as Node)
      ) {
        setIsOpen(false);
      }
    };

    document.addEventListener("mousedown", handleClickOutside);
    return () => document.removeEventListener("mousedown", handleClickOutside);
  }, []);

  const handleSelect = useCallback(
    (articleId: string) => {
      onSelectResult(articleId);
      setQuery("");
      setIsOpen(false);
      setActiveIndex(-1);
    },
    [onSelectResult]
  );

  const handleClear = () => {
    setQuery("");
    setResults([]);
    setIsOpen(false);
    setActiveIndex(-1);
    inputRef.current?.focus();
  };

  const handleKeyDown = (e: React.KeyboardEvent) => {
    if (!isOpen || results.length === 0) return;

    switch (e.key) {
      case "ArrowDown":
        e.preventDefault();
        setActiveIndex((prev) => (prev < results.length - 1 ? prev + 1 : 0));
        break;
      case "ArrowUp":
        e.preventDefault();
        setActiveIndex((prev) => (prev > 0 ? prev - 1 : results.length - 1));
        break;
      case "Enter":
        e.preventDefault();
        if (activeIndex >= 0 && activeIndex < results.length) {
          handleSelect(results[activeIndex].item.id);
        }
        break;
      case "Escape":
        e.preventDefault();
        setIsOpen(false);
        setActiveIndex(-1);
        break;
    }
  };

  // Scroll active item into view
  useEffect(() => {
    if (activeIndex >= 0 && listRef.current) {
      const activeEl = listRef.current.children[activeIndex] as HTMLElement;
      activeEl?.scrollIntoView({ block: "nearest" });
    }
  }, [activeIndex]);

  const getTitleHighlight = (result: HelpSearchResult) => {
    const titleMatch = result.matches?.find((m) => m.key === "title");
    if (titleMatch?.indices) {
      return highlightMatch(result.item.title, titleMatch.indices as [number, number][]);
    }
    return result.item.title;
  };

  return (
    <div ref={containerRef} className={cn("relative", className)}>
      <div className="relative">
        <Search className="absolute left-3 top-1/2 -translate-y-1/2 h-4 w-4 text-muted-foreground" />
        <Input
          ref={inputRef}
          type="search"
          placeholder="Search help articles..."
          value={query}
          onChange={(e) => setQuery(e.target.value)}
          onKeyDown={handleKeyDown}
          className="pl-9 pr-9"
          role="combobox"
          aria-expanded={isOpen && results.length > 0}
          aria-controls="help-search-listbox"
          aria-activedescendant={activeIndex >= 0 ? `help-search-result-${activeIndex}` : undefined}
        />
        {query && (
          <Button
            type="button"
            variant="ghost"
            size="icon"
            className="absolute right-1 top-1/2 -translate-y-1/2 h-7 w-7"
            onClick={handleClear}
          >
            <X className="h-3.5 w-3.5" />
            <span className="sr-only">Clear search</span>
          </Button>
        )}
      </div>

      {isOpen && results.length > 0 && (
        <div
          id="help-search-listbox"
          ref={listRef}
          role="listbox"
          aria-label="Search results"
          className="absolute top-full left-0 right-0 mt-1 py-1 bg-popover border rounded-md shadow-md z-50 max-h-64 overflow-y-auto"
        >
          {results.map((result, index) => (
            <button
              key={result.item.id}
              id={`help-search-result-${index}`}
              type="button"
              role="option"
              aria-selected={index === activeIndex}
              onClick={() => handleSelect(result.item.id)}
              className={cn(
                "w-full text-left px-3 py-2 transition-colors",
                index === activeIndex
                  ? "bg-accent text-accent-foreground"
                  : "hover:bg-accent"
              )}
            >
              <p className="text-sm font-medium">{getTitleHighlight(result)}</p>
              <p className="text-xs text-muted-foreground">{result.item.category}</p>
            </button>
          ))}
        </div>
      )}

      {isOpen && query.trim().length >= 2 && results.length === 0 && (
        <div className="absolute top-full left-0 right-0 mt-1 p-3 bg-popover border rounded-md shadow-md z-50">
          <p className="text-sm text-muted-foreground text-center">
            No results found for &quot;{query}&quot;
          </p>
        </div>
      )}
    </div>
  );
}
