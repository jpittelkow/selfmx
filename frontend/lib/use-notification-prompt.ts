"use client";

import { useRef, useCallback } from "react";

/**
 * Hook that provides a contextual notification permission prompt.
 *
 * Call `promptIfNeeded()` after key user actions (e.g. saving preferences,
 * completing onboarding) to request browser notification permission.
 *
 * The prompt is shown at most once per session to avoid annoying the user.
 * Returns the permission result if a prompt was shown, or null if skipped.
 */
export function useNotificationPrompt() {
  const promptedThisSession = useRef(false);

  const promptIfNeeded = useCallback(
    async (): Promise<NotificationPermission | null> => {
      // Browser doesn't support Notification API
      if (typeof window === "undefined" || !("Notification" in window)) {
        return null;
      }

      // Permission already decided
      if (Notification.permission !== "default") {
        return null;
      }

      // Already prompted this session
      if (promptedThisSession.current) {
        return null;
      }

      promptedThisSession.current = true;

      const result = await Notification.requestPermission();
      return result;
    },
    []
  );

  return { promptIfNeeded };
}
