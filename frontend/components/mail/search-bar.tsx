"use client";

import { useState, useRef, useCallback, useEffect } from "react";
import { Input } from "@/components/ui/input";
import { Button } from "@/components/ui/button";
import { Badge } from "@/components/ui/badge";
import { Search, X, Paperclip, Star, Mail, MailOpen } from "lucide-react";
import type { EmailLabel } from "@/lib/mail-types";

interface SearchBarProps {
  onSearch: (query: string) => void;
  onClear: () => void;
  isSearching: boolean;
  labels: EmailLabel[];
}

const filterChips = [
  { label: "Has attachment", syntax: "has:attachment", icon: Paperclip },
  { label: "Starred", syntax: "is:starred", icon: Star },
  { label: "Unread", syntax: "is:unread", icon: Mail },
  { label: "Read", syntax: "is:read", icon: MailOpen },
] as const;

export function SearchBar({ onSearch, onClear, isSearching, labels }: SearchBarProps) {
  const [query, setQuery] = useState("");
  const inputRef = useRef<HTMLInputElement>(null);
  const debounceRef = useRef<ReturnType<typeof setTimeout>>();

  useEffect(() => {
    return () => {
      if (debounceRef.current) clearTimeout(debounceRef.current);
    };
  }, []);

  const handleQueryChange = useCallback(
    (value: string) => {
      setQuery(value);
      if (debounceRef.current) clearTimeout(debounceRef.current);
      if (value.trim() === "") {
        onClear();
        return;
      }
      debounceRef.current = setTimeout(() => {
        onSearch(value);
      }, 400);
    },
    [onSearch, onClear]
  );

  const handleKeyDown = (e: React.KeyboardEvent) => {
    if (e.key === "Enter" && query.trim()) {
      if (debounceRef.current) clearTimeout(debounceRef.current);
      onSearch(query);
    }
    if (e.key === "Escape") {
      setQuery("");
      onClear();
    }
  };

  const addFilter = (syntax: string) => {
    const newQuery = query ? `${query} ${syntax}` : syntax;
    setQuery(newQuery);
    onSearch(newQuery);
    inputRef.current?.focus();
  };

  const handleClear = () => {
    setQuery("");
    onClear();
    inputRef.current?.focus();
  };

  return (
    <div className="space-y-2">
      <div className="relative">
        <Search className="absolute left-2.5 top-1/2 -translate-y-1/2 h-4 w-4 text-muted-foreground" />
        <Input
          ref={inputRef}
          value={query}
          onChange={(e) => handleQueryChange(e.target.value)}
          onKeyDown={handleKeyDown}
          placeholder="Search emails... (from: to: has:attachment label: is:read)"
          className="pl-9 pr-8 h-9"
          data-mail-search
        />
        {query && (
          <button
            onClick={handleClear}
            className="absolute right-2.5 top-1/2 -translate-y-1/2 text-muted-foreground hover:text-foreground"
          >
            <X className="h-4 w-4" />
          </button>
        )}
      </div>

      {/* Filter chips */}
      <div className="flex gap-1.5 overflow-x-auto pb-1 md:flex-wrap md:overflow-visible md:pb-0">
        {filterChips.map((chip) => (
          <Button
            key={chip.syntax}
            variant="outline"
            size="sm"
            className="h-6 text-xs px-2 gap-1"
            onClick={() => addFilter(chip.syntax)}
          >
            <chip.icon className="h-3 w-3" />
            {chip.label}
          </Button>
        ))}
        {labels.slice(0, 3).map((label) => (
          <Button
            key={label.id}
            variant="outline"
            size="sm"
            className="h-6 text-xs px-2 gap-1"
            onClick={() => addFilter(`label:${label.name}`)}
          >
            {label.color && (
              <span
                className="h-2 w-2 rounded-full shrink-0"
                style={{ backgroundColor: label.color }}
              />
            )}
            {label.name}
          </Button>
        ))}
      </div>

      {isSearching && (
        <div className="flex items-center gap-2">
          <Badge variant="secondary" className="gap-1">
            Search results
            <button onClick={handleClear} className="ml-1 hover:text-foreground">
              <X className="h-3 w-3" />
            </button>
          </Badge>
        </div>
      )}
    </div>
  );
}
