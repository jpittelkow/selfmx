"use client";

import { useEffect, useCallback, useRef, useState } from "react";
import { useRouter } from "next/navigation";
import {
  Command,
  CommandEmpty,
  CommandGroup,
  CommandInput,
  CommandItem,
  CommandList,
} from "@/components/ui/command";
import { Dialog, DialogContent } from "@/components/ui/dialog";
import { SearchResultIcon } from "@/components/search/search-result-icon";
import { useHelp } from "@/components/help/help-provider";
import { getSuggestions, type SearchResult } from "@/lib/search";
import { sanitizeHighlightHtml } from "@/lib/sanitize";

const RECENT_KEY = "search-recent-queries";
const MAX_RECENT = 5;

function getRecentQueries(): string[] {
  if (typeof window === "undefined") return [];
  try {
    const raw = localStorage.getItem(RECENT_KEY);
    if (!raw) return [];
    const parsed = JSON.parse(raw) as unknown;
    return Array.isArray(parsed) ? parsed.slice(0, MAX_RECENT) : [];
  } catch {
    return [];
  }
}

function addRecentQuery(query: string) {
  if (!query.trim()) return;
  const recent = getRecentQueries().filter((q) => q !== query);
  recent.unshift(query.trim());
  try {
    localStorage.setItem(RECENT_KEY, JSON.stringify(recent.slice(0, MAX_RECENT)));
  } catch {
    // ignore
  }
}

interface SearchModalProps {
  open: boolean;
  onOpenChange: (open: boolean) => void;
}

export function SearchModal({ open, onOpenChange }: SearchModalProps) {
  const router = useRouter();
  const { openArticle } = useHelp();
  const [query, setQuery] = useState("");
  const [results, setResults] = useState<SearchResult[]>([]);
  const [loading, setLoading] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [recent, setRecent] = useState<string[]>([]);
  const latestQueryRef = useRef("");

  const runSearch = useCallback(async (q: string) => {
    const trimmed = q.trim();
    if (!trimmed) {
      setResults([]);
      setRecent(getRecentQueries());
      return;
    }
    setLoading(true);
    setError(null);
    try {
      const data = await getSuggestions(trimmed, 10);
      if (latestQueryRef.current === trimmed) {
        setResults(data);
        setRecent([]);
      }
    } catch (err) {
      if (latestQueryRef.current === trimmed) {
        setError(err instanceof Error ? err.message : "Search failed");
        setResults([]);
      }
    } finally {
      setLoading(false);
    }
  }, []);

  useEffect(() => {
    if (!open) return;
    setQuery("");
    setResults([]);
    setError(null);
    setRecent(getRecentQueries());
    latestQueryRef.current = "";
  }, [open]);

  useEffect(() => {
    if (!query.trim()) {
      setResults([]);
      setRecent(getRecentQueries());
      latestQueryRef.current = "";
      return;
    }
    latestQueryRef.current = query.trim();
    const t = setTimeout(() => runSearch(query), 300);
    return () => clearTimeout(t);
  }, [query, runSearch]);

  const handleSelect = useCallback(
    (result: SearchResult) => {
      addRecentQuery(query);
      onOpenChange(false);
      if (result.url.startsWith("help:")) {
        openArticle(result.url.slice(5));
      } else {
        router.push(result.url);
      }
    },
    [query, onOpenChange, router, openArticle]
  );

  const handleRecentSelect = useCallback(
    (q: string) => {
      setQuery(q);
      runSearch(q);
    },
    [runSearch]
  );

  const showRecent = recent.length > 0 && !query.trim();
  const showResults = results.length > 0 && query.trim();
  const showEmpty =
    query.trim() && !loading && !error && results.length === 0;

  return (
    <Dialog open={open} onOpenChange={onOpenChange}>
      <DialogContent className="overflow-hidden p-0 shadow-lg">
        <Command
          shouldFilter={false}
          className="[&_[cmdk-group-heading]]:px-2 [&_[cmdk-group-heading]]:font-medium [&_[cmdk-group-heading]]:text-muted-foreground [&_[cmdk-group]:not([hidden])_~[cmdk-group]]:pt-0 [&_[cmdk-group]]:px-2 [&_[cmdk-input-wrapper]_svg]:h-5 [&_[cmdk-input-wrapper]_svg]:w-5 [&_[cmdk-input]]:h-12 [&_[cmdk-item]]:px-2 [&_[cmdk-item]]:py-3 [&_[cmdk-item]_svg]:h-5 [&_[cmdk-item]_svg]:w-5"
        >
          <CommandInput
            placeholder="Search emails, pages, contacts..."
            value={query}
            onValueChange={setQuery}
          />
          <CommandList className="min-h-[120px]">
            {loading && (
              <div className="py-6 text-center text-sm text-muted-foreground">
                Searching...
              </div>
            )}
            {error && (
              <div className="py-6 text-center text-sm text-destructive">
                {error}
              </div>
            )}
            {showRecent && (
              <CommandGroup heading="Recent searches">
                {recent.map((q) => (
                  <CommandItem
                    key={q}
                    value={q}
                    onSelect={() => handleRecentSelect(q)}
                    className="min-h-[44px]"
                  >
                    <span className="truncate">{q}</span>
                  </CommandItem>
                ))}
              </CommandGroup>
            )}
            {showResults && (
              <CommandGroup heading="Results">
                {results.map((result) => (
                  <CommandItem
                    key={`${result.type}-${result.id}`}
                    value={`${result.type}-${result.id}-${result.title}`}
                    onSelect={() => handleSelect(result)}
                    className="min-h-[44px]"
                  >
                    <SearchResultIcon type={result.type} />
                    <div className="flex min-w-0 flex-1 flex-col gap-0.5">
                      <span
                        className="truncate font-medium"
                        dangerouslySetInnerHTML={{
                          __html: sanitizeHighlightHtml(result.highlight?.title ?? result.title),
                        }}
                      />
                      {result.subtitle && (
                        <span
                          className="truncate text-xs text-muted-foreground"
                          dangerouslySetInnerHTML={{
                            __html: sanitizeHighlightHtml(
                              result.highlight?.subtitle ?? result.subtitle
                            ),
                          }}
                        />
                      )}
                    </div>
                  </CommandItem>
                ))}
              </CommandGroup>
            )}
            {showEmpty && <CommandEmpty>No results found.</CommandEmpty>}
          </CommandList>
        </Command>
      </DialogContent>
    </Dialog>
  );
}
