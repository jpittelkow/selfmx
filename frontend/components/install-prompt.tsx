"use client";

import { useState } from "react";
import { Download } from "lucide-react";
import { useInstallPrompt } from "@/lib/use-install-prompt";
import { useAppConfig } from "@/lib/app-config";
import {
  AlertDialog,
  AlertDialogAction,
  AlertDialogCancel,
  AlertDialogContent,
  AlertDialogDescription,
  AlertDialogFooter,
  AlertDialogHeader,
  AlertDialogTitle,
} from "@/components/ui/alert-dialog";
import { Checkbox } from "@/components/ui/checkbox";

const APP_NAME_FALLBACK = "selfmx";

/**
 * PWA install prompt shown as a dialog after 2+ visits.
 * Uses standard AlertDialog for proper theming in light/dark mode.
 * Dismissible with "Don't show again" (30-day cooldown).
 */
export function InstallPrompt() {
  const { shouldShowBanner, promptInstall, dismissBanner } = useInstallPrompt();
  const { appName } = useAppConfig();
  const [dontShowAgain, setDontShowAgain] = useState(false);
  const [isPrompting, setIsPrompting] = useState(false);

  const appDisplayName = appName || APP_NAME_FALLBACK;

  const handleInstall = async (e: React.MouseEvent) => {
    e.preventDefault(); // prevent AlertDialog auto-close
    setIsPrompting(true);
    try {
      const result = await promptInstall();
      // If user dismissed the native prompt, dismiss our dialog too
      if (result?.outcome === "dismissed") {
        dismissBanner(false);
      }
      // If accepted, shouldShowBanner becomes false automatically (isInstalled → true)
    } finally {
      setIsPrompting(false);
    }
  };

  return (
    <AlertDialog
      open={shouldShowBanner}
      onOpenChange={(open) => {
        if (!open) dismissBanner(dontShowAgain);
      }}
    >
      <AlertDialogContent>
        <AlertDialogHeader>
          <AlertDialogTitle>Install {appDisplayName}</AlertDialogTitle>
          <AlertDialogDescription>
            Add to your home screen for quick access and offline use.
          </AlertDialogDescription>
        </AlertDialogHeader>
        <label className="flex items-center gap-2 cursor-pointer text-sm text-muted-foreground">
          <Checkbox
            checked={dontShowAgain}
            onCheckedChange={(checked) =>
              setDontShowAgain(checked === true)
            }
            aria-label="Don't show again"
          />
          <span>Don&apos;t show again</span>
        </label>
        <AlertDialogFooter>
          <AlertDialogCancel disabled={isPrompting}>
            Not now
          </AlertDialogCancel>
          <AlertDialogAction
            onClick={handleInstall}
            disabled={isPrompting}
          >
            <Download className="h-4 w-4 mr-2" aria-hidden />
            {isPrompting ? "Installing…" : "Install"}
          </AlertDialogAction>
        </AlertDialogFooter>
      </AlertDialogContent>
    </AlertDialog>
  );
}
