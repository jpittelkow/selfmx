"use client";

import { useEffect, useCallback, useRef, useState } from "react";
import { useRouter } from "next/navigation";
import { Search } from "lucide-react";
import {
  Command,
  CommandEmpty,
  CommandGroup,
  CommandItem,
  CommandList,
} from "@/components/ui/command";
import {
  Popover,
  PopoverContent,
  PopoverTrigger,
} from "@/components/ui/popover";
import { Input } from "@/components/ui/input";
import { SearchResultIcon } from "@/components/search/search-result-icon";
import { getSuggestions, type SearchResult } from "@/lib/search";
import { useSearch } from "@/components/search/search-provider";
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

export function SearchInline() {
  const router = useRouter();
  const { setFocusInlineSearch } = useSearch();
  const inputRef = useRef<HTMLInputElement>(null);
  const [query, setQuery] = useState("");
  const [results, setResults] = useState<SearchResult[]>([]);
  const [loading, setLoading] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [recent, setRecent] = useState<string[]>([]);
  const [popoverOpen, setPopoverOpen] = useState(false);
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
    setFocusInlineSearch(() => inputRef.current?.focus() ?? undefined);
    return () => setFocusInlineSearch(null);
  }, [setFocusInlineSearch]);

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
      setPopoverOpen(false);
      setQuery("");
      router.push(result.url);
    },
    [query, router]
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
    <Popover open={popoverOpen} onOpenChange={setPopoverOpen}>
      <PopoverTrigger asChild>
        <div className="relative w-[200px] sm:w-[240px] lg:w-[280px]">
          <Search className="absolute left-2.5 top-1/2 h-4 w-4 -translate-y-1/2 text-muted-foreground" />
          <Input
            ref={inputRef}
            type="search"
            placeholder="Search..."
            value={query}
            onChange={(e) => setQuery(e.target.value)}
            onFocus={() => setPopoverOpen(true)}
            className="h-9 pl-8"
            aria-label="Search"
          />
        </div>
      </PopoverTrigger>
      <PopoverContent
        className="w-[var(--radix-popover-trigger-width)] p-0"
        align="start"
        onOpenAutoFocus={(e) => e.preventDefault()}
      >
        <Command
          shouldFilter={false}
          className="[&_[cmdk-group-heading]]:px-2 [&_[cmdk-group-heading]]:font-medium [&_[cmdk-group-heading]]:text-muted-foreground [&_[cmdk-group]:not([hidden])_~[cmdk-group]]:pt-0 [&_[cmdk-group]]:px-2 [&_[cmdk-item]]:px-2 [&_[cmdk-item]]:py-3 [&_[cmdk-item]_svg]:h-5 [&_[cmdk-item]_svg]:w-5"
        >
          <CommandList className="max-h-[300px]">
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
      </PopoverContent>
    </Popover>
  );
}
