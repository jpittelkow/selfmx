"use client";

import { Suspense, useState } from "react";
import Link from "next/link";
import { usePathname, useSearchParams, useRouter } from "next/navigation";
import { useEffect } from "react";
import { useAuth, isAdminUser } from "@/lib/auth";
import { useMailData } from "@/lib/mail-data-provider";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { Separator } from "@/components/ui/separator";
import { Logo } from "@/components/logo";
import {
  Sheet,
  SheetContent,
} from "@/components/ui/sheet";
import {
  Users,
  Settings,
  ChevronLeft,
  Inbox,
  Star,
  Send,
  FileEdit,
  Trash2,
  AlertOctagon,
  Tag,
  Plus,
  PenLine,
  Mail,
  ChevronsUpDown,
  Sparkles,
  Clock,
} from "lucide-react";
import {
  Popover,
  PopoverTrigger,
  PopoverContent,
} from "@/components/ui/popover";
import {
  Tooltip,
  TooltipTrigger,
  TooltipContent,
} from "@/components/ui/tooltip";
import { cn } from "@/lib/utils";
import { getMailboxAddress } from "@/lib/mail-types";
import { useSidebar } from "@/components/sidebar-context";
import { useIsMobile } from "@/lib/use-mobile";
import { useVersion } from "@/lib/version-provider";
import { useAppConfig } from "@/lib/app-config";
import { toast } from "sonner";
import { api } from "@/lib/api";

const mailFolders = [
  { view: "inbox" as const, label: "Inbox", icon: Inbox },
  { view: "priority" as const, label: "Priority", icon: Sparkles },
  { view: "starred" as const, label: "Starred", icon: Star },
  { view: "sent" as const, label: "Sent", icon: Send },
  { view: "drafts" as const, label: "Drafts", icon: FileEdit },
  { view: "snoozed" as const, label: "Snoozed", icon: Clock },
  { view: "spam" as const, label: "Spam", icon: AlertOctagon },
  { view: "trash" as const, label: "Trash", icon: Trash2 },
];

// Version footer component for sidebar
function SidebarVersionFooter({ isExpanded }: { isExpanded: boolean }) {
  const { version, buildSha } = useVersion();
  const { appName } = useAppConfig();

  if (!version || !isExpanded) {
    return null;
  }

  const displayName = appName || "selfmx";
  const shortSha = buildSha && buildSha !== "development"
    ? buildSha.substring(0, 7)
    : null;

  return (
    <div className="pt-3 border-t px-2 pb-2">
      <Link href="/configuration/changelog" className="block text-center">
        <p className="text-xs text-muted-foreground hover:text-foreground transition-colors">
          {displayName} v{version}
          {shortSha && ` · ${shortSha}`}
        </p>
      </Link>
    </div>
  );
}

function MailboxSwitcher({
  onNavClick,
}: {
  onNavClick?: () => void;
}) {
  const { accessibleMailboxes, activeMailboxId, setActiveMailboxId, mailboxUnreadCounts } = useMailData();
  const [open, setOpen] = useState(false);

  if (accessibleMailboxes.length <= 1) return null;

  const activeMailbox = accessibleMailboxes.find(m => m.id === activeMailboxId);

  const getMailboxLabel = (m: typeof accessibleMailboxes[0]) => getMailboxAddress(m);

  return (
    <div className="px-2 mb-2">
      <Popover open={open} onOpenChange={setOpen}>
        <PopoverTrigger asChild>
          <Button
            variant="outline"
            size="sm"
            className="w-full justify-between text-xs h-8 font-normal"
          >
            <span className="truncate">
              {activeMailbox ? getMailboxLabel(activeMailbox) : "All Mailboxes"}
            </span>
            <ChevronsUpDown className="h-3 w-3 shrink-0 opacity-50 ml-1" />
          </Button>
        </PopoverTrigger>
        <PopoverContent className="w-56 p-1" align="start">
          <button
            className={cn(
              "w-full flex items-center justify-between rounded-sm px-2 py-1.5 text-sm hover:bg-muted transition-colors",
              activeMailboxId === null && "bg-muted font-medium"
            )}
            onClick={() => {
              setActiveMailboxId(null);
              setOpen(false);
              onNavClick?.();
            }}
          >
            <span>All Mailboxes</span>
          </button>
          <div className="h-px bg-border my-1" />
          {accessibleMailboxes.map((m) => {
            const unread = mailboxUnreadCounts[m.id] || 0;
            return (
              <button
                key={m.id}
                className={cn(
                  "w-full flex items-center justify-between rounded-sm px-2 py-1.5 text-sm hover:bg-muted transition-colors",
                  activeMailboxId === m.id && "bg-muted font-medium"
                )}
                onClick={() => {
                  setActiveMailboxId(m.id);
                  setOpen(false);
                  onNavClick?.();
                }}
              >
                <span className="truncate">{getMailboxLabel(m)}</span>
                {unread > 0 && (
                  <span className="text-xs text-muted-foreground ml-2 shrink-0">
                    {unread}
                  </span>
                )}
              </button>
            );
          })}
        </PopoverContent>
      </Popover>
    </div>
  );
}

function MailNavSectionInner({
  isExpanded,
  onNavClick,
}: {
  isExpanded: boolean;
  onNavClick?: () => void;
}) {
  const pathname = usePathname();
  const searchParams = useSearchParams();
  const { labels, unreadCount, accessibleMailboxes, activeMailboxId, setActiveMailboxId, mailboxUnreadCounts, openCompose, refreshLabels } = useMailData();
  const [showNewLabel, setShowNewLabel] = useState(false);
  const [newLabelName, setNewLabelName] = useState("");

  const currentView = pathname?.startsWith("/mail") && !pathname?.startsWith("/mail/settings")
    ? searchParams.get("view") || "inbox"
    : null;
  const currentLabelId = searchParams.get("labelId");

  const handleCreateLabel = async () => {
    if (!newLabelName.trim()) return;
    try {
      await api.post("/email/labels", { name: newLabelName.trim() });
      setNewLabelName("");
      setShowNewLabel(false);
      refreshLabels();
    } catch {
      toast.error("Failed to create label");
    }
  };

  if (!isExpanded) {
    // Collapsed: show compose icon + inbox icon with badge + mailbox switcher popover
    return (
      <div className="flex flex-col items-center gap-1">
        <Button
          variant="default"
          size="icon"
          className="w-10 h-10 shadow-md hover:shadow-lg transition-shadow"
          onClick={() => {
            openCompose();
            onNavClick?.();
          }}
          title="Compose"
        >
          <PenLine className="h-4 w-4" />
        </Button>
        {accessibleMailboxes.length > 1 && (
          <Popover>
            <PopoverTrigger asChild>
              <Button
                variant="ghost"
                size="icon"
                className="w-12 h-12 mx-auto relative"
                title="Switch mailbox"
              >
                <Mail className="h-5 w-5" />
                {activeMailboxId !== null && (
                  <span className="absolute top-1.5 right-1.5 h-2 w-2 rounded-full bg-blue-500" />
                )}
              </Button>
            </PopoverTrigger>
            <PopoverContent className="w-56 p-1" side="right" align="start">
              <button
                className={cn(
                  "w-full flex items-center justify-between rounded-sm px-2 py-1.5 text-sm hover:bg-muted transition-colors",
                  activeMailboxId === null && "bg-muted font-medium"
                )}
                onClick={() => setActiveMailboxId(null)}
              >
                <span>All Mailboxes</span>
              </button>
              <div className="h-px bg-border my-1" />
              {accessibleMailboxes.map((m) => {
                const unread = mailboxUnreadCounts[m.id] || 0;
                const label = getMailboxAddress(m);
                return (
                  <button
                    key={m.id}
                    className={cn(
                      "w-full flex items-center justify-between rounded-sm px-2 py-1.5 text-sm hover:bg-muted transition-colors",
                      activeMailboxId === m.id && "bg-muted font-medium"
                    )}
                    onClick={() => setActiveMailboxId(m.id)}
                  >
                    <span className="truncate">{label}</span>
                    {unread > 0 && (
                      <span className="text-xs text-muted-foreground ml-2 shrink-0">{unread}</span>
                    )}
                  </button>
                );
              })}
            </PopoverContent>
          </Popover>
        )}
        <Button
          variant={pathname?.startsWith("/mail") && !pathname?.startsWith("/mail/settings") ? "secondary" : "ghost"}
          size="icon"
          className={cn(
            "w-12 h-12 mx-auto relative",
            pathname?.startsWith("/mail") && !pathname?.startsWith("/mail/settings") && "bg-muted text-foreground font-medium"
          )}
          title="Inbox"
          asChild
        >
          <Link href="/mail" onClick={onNavClick}>
            <Inbox className="h-5 w-5" />
            {unreadCount > 0 && (
              <span className="absolute top-1.5 right-1.5 h-2 w-2 rounded-full bg-primary" />
            )}
          </Link>
        </Button>
      </div>
    );
  }

  // Expanded: full mail nav
  return (
    <div className="flex flex-col">
      {/* Compose */}
      <div className="px-2 mb-3">
        <Button
          className="w-full justify-center gap-2 shadow-md hover:shadow-lg transition-shadow h-10 text-sm font-medium"
          onClick={() => {
            openCompose();
            onNavClick?.();
          }}
        >
          <PenLine className="h-4 w-4" />
          Compose
        </Button>
      </div>

      {/* Mailbox switcher */}
      <MailboxSwitcher onNavClick={onNavClick} />

      {/* System folders */}
      <nav className="flex flex-col gap-0.5 px-1">
        {mailFolders.map((folder) => {
          const isActive = currentView === folder.view;
          const href =
            folder.view === "inbox"
              ? "/mail"
              : `/mail?view=${folder.view}`;
          return (
            <Link
              key={folder.view}
              href={href}
              onClick={onNavClick}
              className={cn(
                "flex items-center gap-3 w-full rounded-md px-3 py-2 text-sm transition-colors",
                isActive
                  ? "bg-muted text-foreground font-medium"
                  : "text-muted-foreground hover:bg-muted hover:text-foreground"
              )}
            >
              <folder.icon className="h-4 w-4 shrink-0" />
              <span className="flex-1 text-left truncate">{folder.label}</span>
              {folder.view === "inbox" && unreadCount > 0 && (
                <span className="text-xs font-medium bg-primary text-primary-foreground rounded-full px-1.5 py-0.5 min-w-5 text-center">
                  {unreadCount > 99 ? "99+" : unreadCount}
                </span>
              )}
            </Link>
          );
        })}
      </nav>

      {/* Labels */}
      <div className="mt-4 px-1">
        <div className="flex items-center justify-between mb-1.5 px-3">
          <span className="text-xs font-semibold uppercase text-muted-foreground">Labels</span>
          <button
            onClick={() => setShowNewLabel(!showNewLabel)}
            className="text-muted-foreground hover:text-foreground"
            title="Create label"
          >
            <Plus className="h-3.5 w-3.5" />
          </button>
        </div>

        {showNewLabel && (
          <div className="px-2 mb-1.5">
            <Input
              placeholder="Label name"
              value={newLabelName}
              onChange={(e) => setNewLabelName(e.target.value)}
              onKeyDown={(e) => {
                if (e.key === "Enter") handleCreateLabel();
                if (e.key === "Escape") setShowNewLabel(false);
              }}
              className="h-7 text-sm"
              autoFocus
            />
          </div>
        )}

        <nav className="flex flex-col gap-0.5">
          {labels.map((label) => {
            const isActive =
              currentView === "label" && currentLabelId === String(label.id);
            return (
              <Link
                key={label.id}
                href={`/mail?view=label&labelId=${label.id}`}
                onClick={onNavClick}
                className={cn(
                  "flex items-center gap-3 w-full rounded-md px-3 py-1.5 text-sm transition-colors",
                  isActive
                    ? "bg-muted text-foreground font-medium"
                    : "text-muted-foreground hover:bg-muted hover:text-foreground"
                )}
              >
                <Tag
                  className="h-3.5 w-3.5 shrink-0"
                  style={label.color ? { color: label.color } : undefined}
                />
                <span className="flex-1 text-left truncate">{label.name}</span>
                {label.emails_count > 0 && (
                  <span className="text-xs text-muted-foreground">{label.emails_count}</span>
                )}
              </Link>
            );
          })}
        </nav>
      </div>

    </div>
  );
}

function MailNavSection(props: { isExpanded: boolean; onNavClick?: () => void }) {
  return (
    <Suspense fallback={null}>
      <MailNavSectionInner {...props} />
    </Suspense>
  );
}

export function Sidebar() {
  const { user } = useAuth();
  const pathname = usePathname();
  const router = useRouter();
  const { isExpanded, toggleSidebar, isMobileMenuOpen, setMobileMenuOpen } =
    useSidebar();
  const isMobile = useIsMobile();

  const isAdmin =
    typeof isAdminUser === "function" ? isAdminUser(user) : Boolean(user?.is_admin);

  const isMailSettings = pathname?.startsWith("/mail/settings") ?? false;
  const isContacts = pathname?.startsWith("/contacts") ?? false;
  const isConfiguration = pathname?.startsWith("/configuration") ?? false;

  useEffect(() => {
    setMobileMenuOpen(false);
  }, [pathname, isMobile, setMobileMenuOpen]);

  const closeMobileMenu = () => setMobileMenuOpen(false);

  if (isMobile) {
    return (
      <Sheet open={isMobileMenuOpen} onOpenChange={setMobileMenuOpen}>
        <SheetContent
          side="left"
          className="w-96 max-w-[100vw] p-0 flex flex-col"
        >
          <div className="flex flex-col h-full pt-14 px-3 pb-[max(1rem,env(safe-area-inset-bottom))]">
            <div className="flex items-center border-b pb-3 mb-4">
              <Logo variant="full" size="md" />
            </div>
            <div className="flex-1 flex flex-col overflow-y-auto">
              {/* Mail navigation */}
              <MailNavSection isExpanded={true} onNavClick={closeMobileMenu} />

              <Separator orientation="horizontal" className="my-3" />

              {/* Other nav */}
              <nav className="flex flex-col gap-1 px-1">
                <Button
                  variant="ghost"
                  size="default"
                  className={cn(
                    "w-full justify-start gap-3 min-h-11 transition-colors duration-150",
                    isContacts
                      ? "bg-primary/10 text-primary font-medium border-l-2 border-primary rounded-l-none rounded-r-md"
                      : "hover:bg-accent"
                  )}
                  title="Contacts"
                  asChild
                >
                  <Link href="/contacts" onClick={closeMobileMenu}>
                    <Users className="h-5 w-5 flex-shrink-0" />
                    <span>Contacts</span>
                  </Link>
                </Button>
                <Button
                  variant="ghost"
                  size="default"
                  className={cn(
                    "w-full justify-start gap-3 min-h-11 transition-colors duration-150",
                    isMailSettings
                      ? "bg-primary/10 text-primary font-medium border-l-2 border-primary rounded-l-none rounded-r-md"
                      : "hover:bg-accent"
                  )}
                  title="Mail Settings"
                  asChild
                >
                  <Link href="/mail/settings" onClick={closeMobileMenu}>
                    <Settings className="h-5 w-5 flex-shrink-0" />
                    <span>Mail Settings</span>
                  </Link>
                </Button>
              </nav>

              <div className="mt-auto">
                {isAdmin && (
                  <>
                    <Separator orientation="horizontal" className="my-2" />
                    <nav className="flex flex-col gap-2 px-1">
                      <Button
                        variant="ghost"
                        size="default"
                        className={cn(
                          "w-full justify-start gap-3 min-h-11 transition-colors duration-150",
                          isConfiguration
                            ? "bg-primary/10 text-primary font-medium border-l-2 border-primary rounded-l-none rounded-r-md"
                            : "hover:bg-accent"
                        )}
                        title="Configuration"
                        onClick={() => {
                          setMobileMenuOpen(false);
                          router.push("/configuration");
                        }}
                      >
                        <Settings className="h-5 w-5 flex-shrink-0" />
                        <span>Configuration</span>
                      </Button>
                    </nav>
                  </>
                )}
                <SidebarVersionFooter isExpanded={true} />
              </div>
            </div>
          </div>
        </SheetContent>
      </Sheet>
    );
  }

  return (
    <aside
      className={cn(
        "fixed left-0 top-0 h-screen flex flex-col border-r bg-muted/30 z-30 transition-all duration-300",
        isExpanded ? "w-56" : "w-16"
      )}
    >
      <div
        className={cn(
          "flex items-center border-b p-3 h-14",
          isExpanded ? "justify-between" : "justify-center"
        )}
      >
        {isExpanded ? (
          <>
            <Logo variant="full" size="md" />
            <Button
              variant="ghost"
              size="icon"
              onClick={toggleSidebar}
              className="h-11 w-11 flex-shrink-0"
              title="Collapse sidebar"
            >
              <ChevronLeft className="h-4 w-4" />
            </Button>
          </>
        ) : (
          <Tooltip>
            <TooltipTrigger asChild>
              <button
                onClick={toggleSidebar}
                className="flex items-center justify-center hover:opacity-80 transition-opacity"
                aria-label="Expand sidebar"
              >
                <Logo variant="icon" size="sm" />
              </button>
            </TooltipTrigger>
            <TooltipContent side="right">Expand sidebar</TooltipContent>
          </Tooltip>
        )}
      </div>

      <div className="flex-1 p-2 flex flex-col pt-4 overflow-y-auto overflow-x-hidden">
        {/* Mail navigation */}
        <MailNavSection isExpanded={isExpanded} />

        {isExpanded && <Separator orientation="horizontal" className="my-3 mx-1" />}

        {/* Other nav */}
        <nav className="flex flex-col mt-1">
          <Tooltip>
            <TooltipTrigger asChild>
              <Button
                variant="ghost"
                size={isExpanded ? "default" : "icon"}
                className={cn(
                  "min-h-11 transition-colors duration-150",
                  isExpanded ? "w-full justify-start gap-3" : "w-12 h-12 mx-auto",
                  isContacts
                    ? isExpanded
                      ? "bg-primary/10 text-primary font-medium border-l-2 border-primary rounded-l-none rounded-r-md"
                      : "bg-primary/10 text-primary font-medium"
                    : "hover:bg-accent"
                )}
                asChild
              >
                <Link href="/contacts">
                  <Users className="h-5 w-5 flex-shrink-0" />
                  {isExpanded && <span>Contacts</span>}
                </Link>
              </Button>
            </TooltipTrigger>
            {!isExpanded && <TooltipContent side="right">Contacts</TooltipContent>}
          </Tooltip>
          <Tooltip>
            <TooltipTrigger asChild>
              <Button
                variant="ghost"
                size={isExpanded ? "default" : "icon"}
                className={cn(
                  "min-h-11 transition-colors duration-150",
                  isExpanded ? "w-full justify-start gap-3" : "w-12 h-12 mx-auto",
                  isMailSettings
                    ? isExpanded
                      ? "bg-primary/10 text-primary font-medium border-l-2 border-primary rounded-l-none rounded-r-md"
                      : "bg-primary/10 text-primary font-medium"
                    : "hover:bg-accent"
                )}
                asChild
              >
                <Link href="/mail/settings">
                  <Settings className="h-5 w-5 flex-shrink-0" />
                  {isExpanded && <span>Mail Settings</span>}
                </Link>
              </Button>
            </TooltipTrigger>
            {!isExpanded && <TooltipContent side="right">Mail Settings</TooltipContent>}
          </Tooltip>
        </nav>

        <div className="mt-auto">
          {isAdmin && (
            <>
              <Separator orientation="horizontal" className="my-2" />
              <nav className="flex flex-col gap-2">
                <Tooltip>
                  <TooltipTrigger asChild>
                    <Button
                      variant="ghost"
                      size={isExpanded ? "default" : "icon"}
                      className={cn(
                        "min-h-11 transition-colors duration-150",
                        isExpanded ? "w-full justify-start gap-3" : "w-12 h-12 mx-auto",
                        isConfiguration
                          ? isExpanded
                            ? "bg-primary/10 text-primary font-medium border-l-2 border-primary rounded-l-none rounded-r-md"
                            : "bg-primary/10 text-primary font-medium"
                          : "hover:bg-accent"
                      )}
                      asChild
                    >
                      <Link href="/configuration">
                        <Settings className="h-5 w-5 flex-shrink-0" />
                        {isExpanded && <span>Configuration</span>}
                      </Link>
                    </Button>
                  </TooltipTrigger>
                  {!isExpanded && <TooltipContent side="right">Configuration</TooltipContent>}
                </Tooltip>
              </nav>
            </>
          )}
          <SidebarVersionFooter isExpanded={isExpanded} />
        </div>
      </div>
    </aside>
  );
}
