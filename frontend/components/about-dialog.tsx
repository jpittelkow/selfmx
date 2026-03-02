"use client";

import { useVersion } from "@/lib/version-provider";
import { useAppConfig } from "@/lib/app-config";
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogHeader,
  DialogTitle,
} from "@/components/ui/dialog";
import { Separator } from "@/components/ui/separator";

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
  
  const displayName = appName || "selfmx";
  const shortSha = buildSha && buildSha !== "development" 
    ? buildSha.substring(0, 7) 
    : buildSha;

  return (
    <Dialog open={open} onOpenChange={onOpenChange}>
      <DialogContent className="sm:max-w-md">
        <DialogHeader>
          <DialogTitle>About {displayName}</DialogTitle>
          <DialogDescription>
            Application version and system information
          </DialogDescription>
        </DialogHeader>
        
        <div className="space-y-4 py-4">
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
                Built on Sourdough v0.8.2
              </a>
            </div>
          </div>

          <Separator />

          <div className="space-y-2">
            <h4 className="text-sm font-semibold">System Information</h4>
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
        </div>
      </DialogContent>
    </Dialog>
  );
}
