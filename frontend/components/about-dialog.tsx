"use client";

import { useState } from "react";
import { useVersion } from "@/lib/version-provider";
import { useAppConfig } from "@/lib/app-config";
import { useAuth, isAdminUser } from "@/lib/auth";
import { Logo } from "@/components/logo";
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogHeader,
  DialogTitle,
} from "@/components/ui/dialog";
import { Separator } from "@/components/ui/separator";
import {
  Collapsible,
  CollapsibleContent,
  CollapsibleTrigger,
} from "@/components/ui/collapsible";
import { Button } from "@/components/ui/button";
import { ChevronDown } from "lucide-react";
import { cn } from "@/lib/utils";
import Link from "next/link";

interface AboutDialogProps {
  open: boolean;
  onOpenChange: (open: boolean) => void;
}

function formatBuildTime(buildTime: string | null): string {
  if (!buildTime) return "Not available";

  try {
    const date = new Date(buildTime);
    return date.toLocaleString("en-US", {
      year: "numeric",
      month: "short",
      day: "numeric",
      hour: "2-digit",
      minute: "2-digit",
      timeZoneName: "short",
    });
  } catch {
    return buildTime;
  }
}

export function AboutDialog({ open, onOpenChange }: AboutDialogProps) {
  const { version, buildSha, buildTime, phpVersion, laravelVersion } = useVersion();
  const { appName } = useAppConfig();
  const { user } = useAuth();
  const isAdmin = user ? isAdminUser(user) : false;
  const [techOpen, setTechOpen] = useState(isAdmin);

  const displayName = appName || "selfmx";
  const shortSha = buildSha && buildSha !== "development"
    ? buildSha.substring(0, 7)
    : buildSha;

  return (
    <Dialog open={open} onOpenChange={onOpenChange}>
      <DialogContent className="sm:max-w-md">
        <DialogHeader>
          <DialogTitle className="sr-only">About {displayName}</DialogTitle>
          <DialogDescription className="sr-only">
            Application version and system information
          </DialogDescription>
        </DialogHeader>

        <div className="space-y-4 py-4">
          <div className="flex flex-col items-center gap-3 py-2">
            <Logo variant="full" size="lg" />
            <p className="text-sm text-muted-foreground">
              {version ? (
                <Link
                  href="/configuration/changelog"
                  onClick={() => onOpenChange(false)}
                  className="hover:underline text-primary"
                >
                  v{version}
                </Link>
              ) : (
                "Version not available"
              )}
            </p>
          </div>

          <Separator />

          <div className="space-y-2">
            <div className="flex justify-between items-center">
              <span className="text-sm font-medium">Version</span>
              <span className="text-sm text-muted-foreground">
                {version || "Not available"}
              </span>
            </div>
            {shortSha && (
              <div className="flex justify-between items-center">
                <span className="text-sm font-medium">Build</span>
                <span className="text-sm text-muted-foreground font-mono">
                  {shortSha}
                </span>
              </div>
            )}
            {buildTime && (
              <div className="flex justify-between items-center">
                <span className="text-sm font-medium">Build Time</span>
                <span className="text-sm text-muted-foreground">
                  {formatBuildTime(buildTime)}
                </span>
              </div>
            )}
          </div>

          <Separator />

          <div className="space-y-2">
            <div className="flex justify-between items-center">
              <span className="text-sm font-medium">Framework</span>
              <a
                href="https://github.com/Sourdough-start/sourdough"
                target="_blank"
                rel="noopener noreferrer"
                className="text-sm text-muted-foreground hover:text-foreground transition-colors"
              >
                Powered by Sourdough v0.10.3
              </a>
            </div>
          </div>

          {(phpVersion || laravelVersion) && (
            <>
              <Separator />

              <Collapsible open={techOpen} onOpenChange={setTechOpen}>
                <CollapsibleTrigger asChild>
                  <Button variant="ghost" className="w-full justify-between px-0 h-auto py-1 hover:bg-transparent">
                    <span className="text-sm font-semibold">System Information</span>
                    <ChevronDown className={cn(
                      "h-4 w-4 text-muted-foreground transition-transform duration-200",
                      techOpen && "rotate-180"
                    )} />
                  </Button>
                </CollapsibleTrigger>
                <CollapsibleContent>
                  <div className="space-y-2 pt-2">
                    {phpVersion && (
                      <div className="flex justify-between items-center">
                        <span className="text-sm font-medium">PHP</span>
                        <span className="text-sm text-muted-foreground">
                          {phpVersion}
                        </span>
                      </div>
                    )}
                    {laravelVersion && (
                      <div className="flex justify-between items-center">
                        <span className="text-sm font-medium">Laravel</span>
                        <span className="text-sm text-muted-foreground">
                          {laravelVersion}
                        </span>
                      </div>
                    )}
                  </div>
                </CollapsibleContent>
              </Collapsible>
            </>
          )}
        </div>
      </DialogContent>
    </Dialog>
  );
}
