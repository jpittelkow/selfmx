"use client";

import { useState, useEffect, useCallback } from "react";
import { Bell, BellRing, Check, X } from "lucide-react";
import { Button } from "@/components/ui/button";
import {
  WizardStep,
  WizardStepTitle,
  WizardStepDescription,
  WizardStepContent,
} from "@/components/onboarding/wizard-step";
import { useAppConfig } from "@/lib/app-config";
import Link from "next/link";

type PermissionState = "default" | "granted" | "denied" | "unsupported";

export function NotificationsStep() {
  const { features } = useAppConfig();
  const webpushEnabled = features?.webpushEnabled;

  const [permissionState, setPermissionState] =
    useState<PermissionState>("default");
  const [requesting, setRequesting] = useState(false);

  useEffect(() => {
    if (typeof window === "undefined") return;
    if (!("Notification" in window)) {
      setPermissionState("unsupported");
      return;
    }
    setPermissionState(
      Notification.permission as "default" | "granted" | "denied"
    );
  }, []);

  const handleRequestPermission = useCallback(async () => {
    if (!("Notification" in window)) return;
    setRequesting(true);
    try {
      const result = await Notification.requestPermission();
      setPermissionState(result as "default" | "granted" | "denied");
    } finally {
      setRequesting(false);
    }
  }, []);

  return (
    <WizardStep>
      <div className="h-16 w-16 rounded-full bg-primary/10 flex items-center justify-center">
        <Bell className="h-8 w-8 text-primary" />
      </div>

      <WizardStepTitle>Stay informed</WizardStepTitle>

      <WizardStepDescription>
        Choose how you want to receive notifications about important updates.
      </WizardStepDescription>

      <WizardStepContent>
        <div className="space-y-3">
          <div className="p-4 rounded-lg border bg-card text-left">
            <p className="text-sm font-medium mb-2">Available channels:</p>
            <ul className="text-sm text-muted-foreground space-y-1">
              <li>In-app notifications (always on)</li>
              <li>Email notifications</li>
              {webpushEnabled && <li>Browser push notifications</li>}
            </ul>
          </div>

          {webpushEnabled && permissionState !== "unsupported" && (
            <div className="p-4 rounded-lg border bg-card">
              <div className="flex items-center justify-between gap-3">
                <div className="flex items-center gap-3">
                  <BellRing className="h-5 w-5 text-muted-foreground shrink-0" />
                  <div className="text-left">
                    <p className="text-sm font-medium">Push Notifications</p>
                    <p className="text-xs text-muted-foreground">
                      Get notified even when the app isn&apos;t open
                    </p>
                  </div>
                </div>
                {permissionState === "granted" ? (
                  <span className="inline-flex items-center gap-1 rounded-md bg-green-100 dark:bg-green-900/30 px-2.5 py-1 text-xs font-medium text-green-700 dark:text-green-400">
                    <Check className="h-3 w-3" />
                    Enabled
                  </span>
                ) : permissionState === "denied" ? (
                  <span className="inline-flex items-center gap-1 rounded-md bg-muted px-2.5 py-1 text-xs font-medium text-muted-foreground">
                    <X className="h-3 w-3" />
                    Denied
                  </span>
                ) : (
                  <Button
                    size="sm"
                    variant="outline"
                    onClick={handleRequestPermission}
                    disabled={requesting}
                  >
                    <BellRing className="mr-1.5 h-3.5 w-3.5" />
                    Enable Push Notifications
                  </Button>
                )}
              </div>
            </div>
          )}

          <Button asChild variant="outline" className="w-full">
            <Link href="/user/notifications">Configure Notifications</Link>
          </Button>
        </div>
      </WizardStepContent>
    </WizardStep>
  );
}
