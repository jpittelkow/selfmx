"use client";

import { Sidebar } from "@/components/sidebar";
import { Header } from "@/components/header";
import { OfflineIndicator } from "@/components/offline-indicator";
import { InstallPrompt } from "@/components/install-prompt";
import { PostInstallPushPrompt } from "@/components/post-install-push-prompt";
import { SidebarProvider, useSidebar } from "@/components/sidebar-context";
import { SearchProvider } from "@/components/search/search-provider";
import { WizardProvider } from "@/components/onboarding/wizard-provider";
import { HelpProvider } from "@/components/help/help-provider";
import { PageTitleManager } from "@/components/page-title-manager";
import { useOnline } from "@/lib/use-online";
import { cn } from "@/lib/utils";

interface AppShellProps {
  children: React.ReactNode;
}

function AppShellContent({ children }: AppShellProps) {
  const { isExpanded } = useSidebar();
  const { isOffline } = useOnline();

  return (
    <div className={cn("min-h-screen bg-background overflow-x-hidden", isOffline && "pt-10")}>
      <OfflineIndicator />
      <InstallPrompt />
      <PostInstallPushPrompt />
      <PageTitleManager />
      <Sidebar />
      <div
        className={cn(
          "transition-all duration-300",
          "pl-0",
          isExpanded ? "md:pl-56" : "md:pl-16"
        )}
      >
        <Header />
        <main className="min-h-[calc(100vh-3.5rem)] bg-muted/30">
          <div className="mx-auto max-w-7xl px-4 py-6 sm:px-6 lg:px-8">{children}</div>
        </main>
      </div>
    </div>
  );
}

export function AppShell({ children }: AppShellProps) {
  return (
    <SidebarProvider>
      <HelpProvider>
        <SearchProvider>
          <WizardProvider>
            <AppShellContent>{children}</AppShellContent>
          </WizardProvider>
        </SearchProvider>
      </HelpProvider>
    </SidebarProvider>
  );
}
