"use client";

import { useState, useEffect, useCallback } from "react";
import { BellRing, X } from "lucide-react";
import { Button } from "@/components/ui/button";
import {
  Card,
  CardContent,
} from "@/components/ui/card";

const DISMISSED_KEY = "notification_permission_banner_dismissed";

export function NotificationPermissionBanner() {
  const [visible, setVisible] = useState(false);
  const [requesting, setRequesting] = useState(false);

  useEffect(() => {
    if (typeof window === "undefined") return;
    if (!("Notification" in window)) return;
    if (Notification.permission !== "default") return;

    const dismissed = localStorage.getItem(DISMISSED_KEY);
    if (dismissed === "true") return;

    setVisible(true);
  }, []);

  const handleEnable = useCallback(async () => {
    if (!("Notification" in window)) return;
    setRequesting(true);
    try {
      const result = await Notification.requestPermission();
      if (result === "granted" || result === "denied") {
        setVisible(false);
      }
    } finally {
      setRequesting(false);
    }
  }, []);

  const handleDismiss = useCallback(() => {
    localStorage.setItem(DISMISSED_KEY, "true");
    setVisible(false);
  }, []);

  if (!visible) return null;

  return (
    <Card className="border-primary/20 bg-primary/5">
      <CardContent className="flex flex-col gap-3 p-4 sm:flex-row sm:items-center sm:justify-between">
        <div className="flex items-start gap-3">
          <div className="rounded-full bg-primary/10 p-2 shrink-0">
            <BellRing className="h-4 w-4 text-primary" />
          </div>
          <div>
            <p className="text-sm font-medium">Enable push notifications</p>
            <p className="text-xs text-muted-foreground">
              Get notified about important updates even when you&apos;re not
              using the app.
            </p>
          </div>
        </div>
        <div className="flex items-center gap-2 shrink-0">
          <Button
            size="sm"
            onClick={handleEnable}
            disabled={requesting}
          >
            Enable
          </Button>
          <Button
            size="sm"
            variant="ghost"
            onClick={handleDismiss}
            aria-label="Dismiss notification permission banner"
          >
            <X className="h-4 w-4" />
          </Button>
        </div>
      </CardContent>
    </Card>
  );
}
