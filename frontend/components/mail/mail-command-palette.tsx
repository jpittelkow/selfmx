"use client";

import { useEffect, useCallback } from "react";
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
import { mailCommands } from "@/lib/mail-commands";
import { useMailData } from "@/lib/mail-data-provider";

interface MailCommandPaletteProps {
  open: boolean;
  onOpenChange: (open: boolean) => void;
  onImport?: () => void;
}

export function MailCommandPalette({ open, onOpenChange, onImport }: MailCommandPaletteProps) {
  const router = useRouter();
  const { openCompose } = useMailData();

  // Ctrl+K to toggle (mail-specific) — only when on mail page
  useEffect(() => {
    const handleKeyDown = (e: KeyboardEvent) => {
      if ((e.metaKey || e.ctrlKey) && e.key === "k") {
        e.preventDefault();
        onOpenChange(!open);
      }
    };
    document.addEventListener("keydown", handleKeyDown);
    return () => document.removeEventListener("keydown", handleKeyDown);
  }, [open, onOpenChange]);

  const handleSelect = useCallback(
    (actionKey: string) => {
      onOpenChange(false);

      if (actionKey === "compose") {
        openCompose();
        return;
      }

      if (actionKey.startsWith("navigate:")) {
        const view = actionKey.replace("navigate:", "");
        const url = view === "inbox" ? "/mail" : `/mail?view=${view}`;
        router.push(url);
        return;
      }

      if (actionKey === "search") {
        // Focus the search input
        setTimeout(() => {
          const searchInput = document.querySelector<HTMLInputElement>("[data-mail-search]");
          searchInput?.focus();
        }, 100);
        return;
      }

      if (actionKey === "import") {
        onImport?.();
        return;
      }
    },
    [onOpenChange, openCompose, router, onImport]
  );

  return (
    <Dialog open={open} onOpenChange={onOpenChange}>
      <DialogContent className="overflow-hidden p-0 shadow-lg max-w-md">
        <Command className="[&_[cmdk-group-heading]]:text-muted-foreground [&_[cmdk-group-heading]]:px-2 [&_[cmdk-group-heading]]:font-medium [&_[cmdk-group-heading]]:text-xs">
          <CommandInput placeholder="Type a command..." />
          <CommandList>
            <CommandEmpty>No commands found.</CommandEmpty>
            <CommandGroup heading="Mail">
              {mailCommands.map((cmd) => (
                <CommandItem
                  key={cmd.id}
                  value={[cmd.label, ...cmd.keywords].join(" ")}
                  onSelect={() => handleSelect(cmd.action)}
                  className="gap-2"
                >
                  <cmd.icon className="h-4 w-4 shrink-0 text-muted-foreground" />
                  <span>{cmd.label}</span>
                </CommandItem>
              ))}
            </CommandGroup>
          </CommandList>
        </Command>
      </DialogContent>
    </Dialog>
  );
}
