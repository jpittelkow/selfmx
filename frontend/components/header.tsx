"use client";

import { Menu, Search } from "lucide-react";
import { Button } from "@/components/ui/button";
import { UserDropdown } from "@/components/user-dropdown";
import { useSidebar } from "@/components/sidebar-context";
import { useSearch } from "@/components/search/search-provider";
import { SearchInline } from "@/components/search/search-inline";
import { NotificationBell } from "@/components/notifications/notification-bell";
import { HelpIcon } from "@/components/help/help-icon";
import { ThemeToggle } from "@/components/theme-toggle";
import { AppBreadcrumbs } from "@/components/app-breadcrumbs";
import { isMacPlatform } from "@/lib/browser-utils";

const isMac = isMacPlatform();

export function Header() {
  const { setMobileMenuOpen } = useSidebar();
  const { setOpen: setSearchOpen, searchEnabled } = useSearch();

  return (
    <header className="sticky top-0 z-40 border-b bg-background/95 backdrop-blur-lg supports-[backdrop-filter]:bg-background/60 safe-area-top">
      <div className="flex h-14 w-full items-center px-4 gap-2 overflow-hidden">
        {/* Left: mobile menu + breadcrumbs */}
        <Button
          variant="ghost"
          size="icon"
          className="md:hidden h-11 w-11 min-w-11 shrink-0 transition-transform duration-150 hover:scale-[1.03]"
          onClick={() => setMobileMenuOpen(true)}
          title="Open menu"
          aria-label="Open menu"
        >
          <Menu className="h-5 w-5" />
        </Button>
        <div className="hidden md:block min-w-0">
          <AppBreadcrumbs />
        </div>

        {/* Right: actions */}
        <div className="flex items-center gap-2 ml-auto">
          {/* Search group */}
          {searchEnabled && (
            <>
              <Button
                variant="ghost"
                size="sm"
                className="md:hidden h-9 gap-1.5 px-2 shrink-0 text-muted-foreground hover:text-foreground transition-transform duration-150 hover:scale-[1.03]"
                onClick={() => setSearchOpen(true)}
                title="Search"
                aria-label="Search"
              >
                <Search className="h-4 w-4" />
                <span className="hidden sm:inline text-xs">
                  {isMac ? "⌘K" : "Ctrl+K"}
                </span>
              </Button>
              <div className="hidden md:block">
                <SearchInline />
              </div>
            </>
          )}

          {/* Utility group: help + notifications */}
          <HelpIcon className="shrink-0" />
          <NotificationBell />

          {/* User group: theme + profile */}
          <div className="hidden sm:block">
            <ThemeToggle />
          </div>
          <UserDropdown />
        </div>
      </div>
    </header>
  );
}
