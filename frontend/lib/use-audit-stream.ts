"use client";

import { useEffect, useRef, useState } from "react";
import { getEcho } from "@/lib/echo";

export interface AuditLogStreamPayload {
  id: number;
  user_id: number | null;
  action: string;
  severity: string;
  auditable_type: string | null;
  auditable_id: number | null;
  old_values: Record<string, unknown> | null;
  new_values: Record<string, unknown> | null;
  ip_address: string | null;
  user_agent: string | null;
  correlation_id: string | null;
  created_at: string;
  user?: {
    id: number;
    name: string;
    email: string;
  };
}

export type AuditStreamStatus = "disconnected" | "connecting" | "connected" | "unavailable";

/**
 * Subscribe to real-time audit log events on the private audit-logs channel.
 * Only works when Reverb is configured and the user is an admin (channel auth).
 * Call only when enabled and in the browser.
 */
export function useAuditStream(
  enabled: boolean,
  onNewLog: (log: AuditLogStreamPayload) => void
): { status: AuditStreamStatus } {
  const [status, setStatus] = useState<AuditStreamStatus>("disconnected");
  const onNewLogRef = useRef(onNewLog);
  onNewLogRef.current = onNewLog;

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

      const channel = echo.private("audit-logs");

      channel.listen(".AuditLogCreated", (payload: unknown) => {
        const data = payload as AuditLogStreamPayload;
        if (data?.id != null && typeof data.action === "string") {
          onNewLogRef.current({
            id: data.id,
            user_id: data.user_id ?? null,
            action: data.action,
            severity: data.severity ?? "info",
            auditable_type: data.auditable_type ?? null,
            auditable_id: data.auditable_id ?? null,
            old_values: data.old_values ?? null,
            new_values: data.new_values ?? null,
            ip_address: data.ip_address ?? null,
            user_agent: data.user_agent ?? null,
            correlation_id: data.correlation_id ?? null,
            created_at: data.created_at ?? new Date().toISOString(),
            user: data.user,
          });
        }
      });

      setStatus("connected");

      cleanup = () => {
        try {
          channel.stopListening(".AuditLogCreated");
          echo.leave("audit-logs");
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

  return { status };
}
