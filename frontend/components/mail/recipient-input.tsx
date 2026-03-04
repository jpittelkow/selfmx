"use client";

import { useState, useRef, useEffect } from "react";
import { Badge } from "@/components/ui/badge";
import { Label } from "@/components/ui/label";
import { ContactAvatar } from "@/components/contacts/contact-avatar";
import { X, AlertTriangle } from "lucide-react";
import {
  Tooltip,
  TooltipContent,
  TooltipTrigger,
} from "@/components/ui/tooltip";
import { api } from "@/lib/api";
import { cn } from "@/lib/utils";

interface Contact {
  id: number;
  email_address: string;
  display_name: string | null;
}

export interface SuppressionWarning {
  reason: string;
  detail?: string | null;
}

interface RecipientInputProps {
  label: string;
  value: string[];
  onChange: (addresses: string[]) => void;
  placeholder?: string;
  warnings?: Record<string, SuppressionWarning>;
}

export function RecipientInput({ label, value, onChange, placeholder, warnings }: RecipientInputProps) {
  const [inputValue, setInputValue] = useState("");
  const [suggestions, setSuggestions] = useState<Contact[]>([]);
  const [isOpen, setIsOpen] = useState(false);
  const [highlightIndex, setHighlightIndex] = useState(-1);
  const inputRef = useRef<HTMLInputElement>(null);
  const containerRef = useRef<HTMLDivElement>(null);
  const debounceRef = useRef<ReturnType<typeof setTimeout>>();

  // Debounced autocomplete search
  useEffect(() => {
    if (inputValue.length < 2) {
      setSuggestions([]);
      setIsOpen(false);
      return;
    }

    if (debounceRef.current) clearTimeout(debounceRef.current);
    debounceRef.current = setTimeout(async () => {
      try {
        const res = await api.get<{ contacts: Contact[] }>(
          `/contacts/autocomplete?q=${encodeURIComponent(inputValue)}&limit=8`
        );
        const filtered = (res.data.contacts || []).filter(
          (c) => !value.includes(c.email_address)
        );
        setSuggestions(filtered);
        setIsOpen(filtered.length > 0);
        setHighlightIndex(-1);
      } catch {
        setSuggestions([]);
        setIsOpen(false);
      }
    }, 200);

    return () => {
      if (debounceRef.current) clearTimeout(debounceRef.current);
    };
  }, [inputValue, value]);

  // Close dropdown when clicking outside
  useEffect(() => {
    const handleClickOutside = (e: MouseEvent) => {
      if (containerRef.current && !containerRef.current.contains(e.target as Node)) {
        setIsOpen(false);
      }
    };
    document.addEventListener("mousedown", handleClickOutside);
    return () => document.removeEventListener("mousedown", handleClickOutside);
  }, []);

  const addRecipient = (address: string) => {
    const normalized = address.trim().toLowerCase();
    if (normalized && !value.includes(normalized)) {
      onChange([...value, normalized]);
    }
    setInputValue("");
    setSuggestions([]);
    setIsOpen(false);
    inputRef.current?.focus();
  };

  const removeRecipient = (address: string) => {
    onChange(value.filter((a) => a !== address));
  };

  const handleKeyDown = (e: React.KeyboardEvent) => {
    if (e.key === "ArrowDown" && isOpen) {
      e.preventDefault();
      setHighlightIndex((prev) => Math.min(prev + 1, suggestions.length - 1));
    } else if (e.key === "ArrowUp" && isOpen) {
      e.preventDefault();
      setHighlightIndex((prev) => Math.max(prev - 1, 0));
    } else if (e.key === "Enter") {
      e.preventDefault();
      if (isOpen && highlightIndex >= 0 && suggestions[highlightIndex]) {
        addRecipient(suggestions[highlightIndex].email_address);
      } else if (inputValue.trim()) {
        addRecipient(inputValue);
      }
    } else if (e.key === ",") {
      e.preventDefault();
      if (inputValue.trim()) {
        addRecipient(inputValue);
      }
    } else if (e.key === "Backspace" && !inputValue && value.length > 0) {
      removeRecipient(value[value.length - 1]);
    } else if (e.key === "Escape") {
      setIsOpen(false);
    }
  };

  const handlePaste = (e: React.ClipboardEvent) => {
    const text = e.clipboardData.getData("text");
    const addresses = text
      .split(/[,;\s]+/)
      .map((a) => a.trim().toLowerCase())
      .filter(Boolean);
    if (addresses.length > 1) {
      e.preventDefault();
      const newAddresses = addresses.filter((a) => !value.includes(a));
      onChange([...value, ...newAddresses]);
    }
  };

  return (
    <div className="space-y-1" ref={containerRef}>
      {label && <Label className="text-xs">{label}</Label>}
      <div className="relative">
        <div className="flex flex-wrap gap-1 p-1.5 border rounded-md min-h-9 focus-within:ring-1 focus-within:ring-ring">
          {value.map((addr) => {
            const warning = warnings?.[addr];
            return (
              <Tooltip key={addr}>
                <TooltipTrigger asChild>
                  <Badge
                    variant={warning ? "destructive" : "secondary"}
                    className={cn("gap-1 h-6 text-xs shrink-0", warning && "bg-amber-100 text-amber-900 hover:bg-amber-200 dark:bg-amber-900/30 dark:text-amber-400")}
                  >
                    {warning && <AlertTriangle className="h-3 w-3" />}
                    {addr}
                    <button
                      type="button"
                      onClick={() => removeRecipient(addr)}
                      className="ml-0.5 hover:text-foreground"
                      title="Remove"
                    >
                      <X className="h-3 w-3" />
                    </button>
                  </Badge>
                </TooltipTrigger>
                {warning && (
                  <TooltipContent>
                    <p>Suppressed ({warning.reason}){warning.detail ? `: ${warning.detail}` : ""}</p>
                  </TooltipContent>
                )}
              </Tooltip>
            );
          })}
          <input
            ref={inputRef}
            value={inputValue}
            onChange={(e) => setInputValue(e.target.value)}
            onKeyDown={handleKeyDown}
            onPaste={handlePaste}
            onBlur={() => {
              // Add any typed text as address on blur (delayed to allow dropdown click)
              if (inputValue.trim()) {
                setTimeout(() => {
                  if (inputValue.trim()) {
                    addRecipient(inputValue);
                  }
                }, 200);
              }
            }}
            placeholder={value.length === 0 ? (placeholder || "recipient@example.com") : ""}
            className="flex-1 min-w-[120px] outline-none bg-transparent text-sm h-6 px-1"
          />
        </div>

        {/* Autocomplete dropdown */}
        {isOpen && suggestions.length > 0 && (
          <div className="absolute top-full left-0 right-0 z-50 mt-1 bg-popover border rounded-md shadow-md overflow-hidden">
            {suggestions.map((contact, index) => (
              <button
                key={contact.id}
                type="button"
                className={cn(
                  "w-full flex items-center gap-2 px-3 py-2 text-left text-sm hover:bg-accent transition-colors",
                  index === highlightIndex && "bg-accent"
                )}
                onMouseDown={(e) => {
                  e.preventDefault(); // Prevent blur
                  addRecipient(contact.email_address);
                }}
                onMouseEnter={() => setHighlightIndex(index)}
              >
                <ContactAvatar
                  name={contact.display_name}
                  email={contact.email_address}
                  size="sm"
                />
                <div className="min-w-0">
                  <div className="font-medium truncate">
                    {contact.display_name || contact.email_address}
                  </div>
                  {contact.display_name && (
                    <div className="text-xs text-muted-foreground truncate">
                      {contact.email_address}
                    </div>
                  )}
                </div>
              </button>
            ))}
          </div>
        )}
      </div>
    </div>
  );
}
