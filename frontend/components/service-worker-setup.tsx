"use client";

import { useEffect, useRef } from "react";
import { toast } from "sonner";
import {
  registerServiceWorker,
  activateWaitingServiceWorker,
  isServiceWorkerSupported,
} from "@/lib/service-worker";

/**
 * Registers the service worker on mount and shows a toast when an update is available.
 * Also listens for NAVIGATE messages from the SW (fallback when client.navigate() is
 * unavailable, e.g. notification click on some mobile browsers).
 * Follows the ErrorHandlerSetup pattern - mounts once in app root, runs silently.
 */
export function ServiceWorkerSetup() {
  const hasShownUpdateRef = useRef(false);

  useEffect(() => {
    let unregister: (() => void) | undefined;
    registerServiceWorker(
      (registration) => {
        // New version available - show toast with refresh option
        if (hasShownUpdateRef.current) return;
        hasShownUpdateRef.current = true;

        toast.info("New version available", {
          action: {
            label: "Refresh",
            onClick: async () => {
              await activateWaitingServiceWorker();
            },
          },
          duration: Infinity,
        });
      },
      () => {
        // New SW has taken control - reload to get fresh assets
        window.location.reload();
      }
    ).then((result) => {
      unregister = result?.unregister;
    });

    return () => {
      unregister?.();
    };
  }, []);

  // Listen for NAVIGATE messages from the service worker.
  // This is a fallback for notification clicks when client.navigate() is not
  // supported (e.g. some mobile browsers). The SW posts { type: 'NAVIGATE', url }
  // and we navigate here on the page side.
  useEffect(() => {
    if (!isServiceWorkerSupported()) return;

    const handler = (event: MessageEvent) => {
      if (event.data?.type === "NAVIGATE" && typeof event.data.url === "string") {
        // Validate same-origin to prevent navigation to arbitrary URLs
        try {
          const target = new URL(event.data.url);
          if (target.origin === window.location.origin) {
            window.location.href = event.data.url;
          }
        } catch {
          // Invalid URL, ignore
        }
      }
      if (event.data?.type === "PUSH_RECEIVED") {
        // Foreground push — system notification was suppressed to avoid duplicates.
        // Dispatch event with the notification data so the bell can display it
        // immediately without waiting for the next API poll.
        window.dispatchEvent(
          new CustomEvent("push-received", { detail: event.data.data ?? null })
        );
      }
    };
    navigator.serviceWorker.addEventListener("message", handler);
    return () => {
      navigator.serviceWorker.removeEventListener("message", handler);
    };
  }, []);

  return null;
}
