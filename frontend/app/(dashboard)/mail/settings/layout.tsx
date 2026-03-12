"use client";

import { useEffect, useState } from "react";
import { useRouter } from "next/navigation";
import Link from "next/link";
import { usePathname } from "next/navigation";
import { useAuth } from "@/lib/auth";
import { useIsMobile } from "@/lib/use-mobile";
import { cn } from "@/lib/utils";
import { Button } from "@/components/ui/button";
import {
  Sheet,
  SheetContent,
  SheetHeader,
  SheetTitle,
} from "@/components/ui/sheet";
import {
  ListFilter,
  PenLine,
  ShieldBan,
  Upload,
  Menu,
  Settings,
} from "lucide-react";
import type { LucideIcon } from "lucide-react";

type NavItem = {
  name: string;
  href: string;
  icon: LucideIcon;
  description: string;
};

const navigationItems: NavItem[] = [
  { name: "Rules", href: "/mail/settings/rules", icon: ListFilter, description: "Manage email filter rules" },
  { name: "Signatures", href: "/mail/settings/signatures", icon: PenLine, description: "Manage email signatures" },
  { name: "Spam Filter", href: "/mail/settings/spam", icon: ShieldBan, description: "Allow and block sender lists" },
  { name: "Import Emails", href: "/mail/settings/import", icon: Upload, description: "Import mbox or eml files" },
];

function Navigation({ pathname }: { pathname: string }) {
  return (
    <nav className="space-y-0.5" aria-label="Mail settings navigation">
      {navigationItems.map((item) => {
        const isActive = pathname === item.href;
        return (
          <Link
            key={item.name}
            href={item.href}
            className={cn(
              "flex items-center gap-3 rounded-lg px-3 py-2 min-h-10 text-sm transition-colors",
              isActive
                ? "bg-muted text-foreground font-medium border border-border"
                : "text-muted-foreground hover:bg-muted hover:text-foreground"
            )}
          >
            <item.icon className="h-4 w-4 shrink-0" />
            <div className="flex flex-col min-w-0">
              <span className="font-medium truncate">{item.name}</span>
              <span className="text-xs truncate text-muted-foreground">
                {item.description}
              </span>
            </div>
          </Link>
        );
      })}
    </nav>
  );
}

export default function MailSettingsLayout({
  children,
}: {
  children: React.ReactNode;
}) {
  const pathname = usePathname();
  const router = useRouter();
  const { user, isLoading } = useAuth();
  const [isMenuOpen, setIsMenuOpen] = useState(false);
  const isMobile = useIsMobile();

  useEffect(() => {
    if (!isLoading && !user) {
      router.push("/login");
    }
  }, [user, isLoading, router]);

  // Close drawer on navigation
  useEffect(() => {
    setIsMenuOpen(false);
  }, [pathname]);

  if (isLoading) {
    return (
      <div className="min-h-screen flex items-center justify-center">
        <div className="animate-spin rounded-full h-8 w-8 border-b-2 border-primary"></div>
      </div>
    );
  }

  if (!user) {
    return null;
  }

  // Mobile layout: header with menu button + Sheet drawer
  if (isMobile) {
    return (
      <div className="px-4 py-4">
        {/* Mobile header */}
        <div className="mb-6 flex items-center gap-3">
          <Button
            variant="ghost"
            size="icon"
            className="h-11 w-11 min-w-11 shrink-0"
            onClick={() => setIsMenuOpen(true)}
            title="Open mail settings menu"
            aria-label="Open mail settings menu"
          >
            <Menu className="h-5 w-5" />
          </Button>
          <div className="flex items-center gap-2">
            <Settings className="h-5 w-5 text-muted-foreground" />
            <span className="font-semibold">Mail Settings</span>
          </div>
        </div>

        {/* Mobile drawer */}
        <Sheet open={isMenuOpen} onOpenChange={setIsMenuOpen}>
          <SheetContent
            side="left"
            className="w-96 max-w-[100vw] p-0 flex flex-col"
          >
            <div className="flex flex-col h-full pt-14 px-3 pb-4">
              <SheetHeader className="mb-4 border-b pb-3">
                <div className="flex items-center gap-2">
                  <Settings className="h-5 w-5 text-muted-foreground" />
                  <SheetTitle>Mail Settings</SheetTitle>
                </div>
              </SheetHeader>
              <div className="flex-1 overflow-y-auto">
                <Navigation pathname={pathname} />
              </div>
            </div>
          </SheetContent>
        </Sheet>

        {/* Main content */}
        <main className="flex-1 min-w-0">{children}</main>
      </div>
    );
  }

  // Desktop layout: sidebar on left + content on right
  return (
    <div className="flex flex-row h-[calc(100vh-3.5rem)]">
      {/* Sidebar */}
      <aside className="w-64 min-w-64 shrink-0 flex flex-col border-r overflow-y-auto overflow-x-hidden p-4">
        <div className="mb-4">
          <div className="flex items-center gap-2">
            <Settings className="h-5 w-5 text-muted-foreground" />
            <span className="font-semibold">Mail Settings</span>
          </div>
        </div>
        <Navigation pathname={pathname} />
      </aside>

      {/* Main content */}
      <main className="flex-1 min-w-0 overflow-y-auto p-6 lg:p-8">{children}</main>
    </div>
  );
}
