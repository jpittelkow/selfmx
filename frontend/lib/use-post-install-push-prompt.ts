"use client";

import { useState, useEffect, useCallback } from "react";
import { isWebPushSupported } from "./web-push";

const STORAGE_DISMISSED_AT = "push-prompt-dismissed-at";
const RE_PROMPT_DAYS = 30;

function isDismissedRecently(): boolean {
  const raw = localStorage.getItem(STORAGE_DISMISSED_AT);
  if (!raw) return false;
  const dismissedAt = parseInt(raw, 10);
  if (isNaN(dismissedAt)) return false;
  const daysSince = (Date.now() - dismissedAt) / (1000 * 60 * 60 * 24);
  return daysSince < RE_PROMPT_DAYS;
}

function isStandaloneMode(): boolean {
  return (
    window.matchMedia("(display-mode: standalone)").matches ||
    (window.navigator as unknown as { standalone?: boolean }).standalone === true
  );
}

export interface UsePostInstallPushPromptResult {
  shouldShowPushPrompt: boolean;
  dismissPushPrompt: () => void;
}

/**
 * Shows a push notification prompt when the app is running in standalone (PWA) mode
 * and push notifications are not yet subscribed on this device.
 */
export function usePostInstallPushPrompt(): UsePostInstallPushPromptResult {
  const [shouldShow, setShouldShow] = useState(false);

  useEffect(() => {
    if (typeof window === "undefined") return;
    if (!isStandaloneMode()) return;
    if (!isWebPushSupported()) return;
    if (isDismissedRecently()) return;

    if (Notification.permission === "denied") {
      return;
    }

    if (Notification.permission === "granted") {
      // Already has permission — check if subscribed on this device.
      // On Android, Chrome and the installed PWA share the same origin and
      // service worker, so a subscription from the browser tab is valid here.
      // Still prompt if there's no subscription at all.
      navigator.serviceWorker.ready
        .then((reg) => reg.pushManager.getSubscription())
        .then((sub) => {
          if (!sub) {
            setShouldShow(true);
          }
        });
      return;
    }

    // Permission is 'default' — show prompt
    setShouldShow(true);
  }, []);

  const dismiss = useCallback(() => {
    localStorage.setItem(STORAGE_DISMISSED_AT, String(Date.now()));
    setShouldShow(false);
  }, []);

  return { shouldShowPushPrompt: shouldShow, dismissPushPrompt: dismiss };
}
