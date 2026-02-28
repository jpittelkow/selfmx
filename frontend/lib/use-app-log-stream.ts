"use client";

import { useCallback, useEffect, useRef, useState } from "react";
import { getEcho } from "@/lib/echo";

export interface AppLogStreamPayload {
  level: string;
  message: string;
  context: Record<string, unknown>;
  correlation_id: string | null;
  user_id: number | null;
  timestamp: string;
}

export interface AppLogEntry extends AppLogStreamPayload {
  id: string;
}

export type AppLogStreamStatus =
  | "disconnected"
  | "connecting"
  | "connected"
  | "unavailable";

const MAX_LOGS = 500;

/**
 * Subscribe to real-time application log events on the private app-logs channel.
 * Only works when Reverb is configured and the user is an admin (channel auth).
 * Call only when enabled and in the browser.
 */
export function useAppLogStream(enabled: boolean): {
  status: AppLogStreamStatus;
  logs: AppLogEntry[];
  clearLogs: () => void;
} {
  const [status, setStatus] = useState<AppLogStreamStatus>("disconnected");
  const [logs, setLogs] = useState<AppLogEntry[]>([]);
  const idRef = useRef(0);

  const clearLogs = useCallback(() => {
    setLogs([]);
  }, []);

  useEffect(() => {
    if (!enabled || typeof window === "undefined") {
      setStatus("disconnected");
      return;
    }

    let cancelled = false;
    let cleanup: (() => void) | undefined;

    setStatus("connecting");

    getEcho().then((echo) => {
      if (cancelled) {
        setStatus("disconnected");
        return;
      }
      if (!echo) {
        setStatus("unavailable");
        return;
      }

      const channel = echo.private("app-logs");

      channel.listen(".AppLogCreated", (payload: unknown) => {
        const data = payload as AppLogStreamPayload;
        if (
          !data ||
          typeof data.level !== "string" ||
          typeof data.message !== "string"
        ) {
          return;
        }
        const entry: AppLogEntry = {
          id: `app-log-${idRef.current++}-${Date.now()}`,
          level: data.level ?? "info",
          message: data.message,
          context: data.context ?? {},
          correlation_id: data.correlation_id ?? null,
          user_id: data.user_id ?? null,
          timestamp: data.timestamp ?? new Date().toISOString(),
        };
        setLogs((prev) => {
          const next = [entry, ...prev];
          return next.length > MAX_LOGS ? next.slice(0, MAX_LOGS) : next;
        });
      });

      setStatus("connected");

      cleanup = () => {
        try {
          channel.stopListening(".AppLogCreated");
          echo.leave("app-logs");
        } catch {
          // ignore
        }
        setStatus("disconnected");
      };
    });

    return () => {
      cancelled = true;
      cleanup?.();
      if (!cleanup) setStatus("disconnected");
    };
  }, [enabled]);

  return { status, logs, clearLogs };
}
