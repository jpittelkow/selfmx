"use client";

import { useEffect, useRef, useState } from "react";

export interface EmailReceivedPayload {
  email_id: number;
  thread_id: number | null;
  mailbox_id: number;
  from_address: string;
  from_name: string | null;
  subject: string | null;
  sent_at: string;
  is_spam: boolean;
}

export type MailStreamStatus = "disconnected" | "connecting" | "connected" | "unavailable";

/**
 * Subscribe to real-time email events on the private mail.{userId} channel.
 * Only works when Reverb is configured and the user is authenticated.
 */
export function useMailStream(
  userId: number | null,
  enabled: boolean,
  onNewEmail: (payload: EmailReceivedPayload) => void
): { status: MailStreamStatus } {
  const [status, setStatus] = useState<MailStreamStatus>("disconnected");
  const onNewEmailRef = useRef(onNewEmail);
  onNewEmailRef.current = onNewEmail;

  useEffect(() => {
    if (!enabled || !userId || typeof window === "undefined") {
      setStatus("disconnected");
      return;
    }

    let cancelled = false;
    let cleanup: (() => void) | undefined;

    setStatus("connecting");

    import("@/lib/echo").then(({ getEcho }) => getEcho()).then((echo) => {
      if (cancelled) {
        setStatus("disconnected");
        return;
      }
      if (!echo) {
        setStatus("unavailable");
        return;
      }

      const channel = echo.private(`mail.${userId}`);

      channel.listen(".EmailReceived", (payload: unknown) => {
        const data = payload as EmailReceivedPayload;
        if (data?.email_id != null) {
          onNewEmailRef.current({
            email_id: data.email_id,
            thread_id: data.thread_id ?? null,
            mailbox_id: data.mailbox_id,
            from_address: data.from_address ?? "",
            from_name: data.from_name ?? null,
            subject: data.subject ?? null,
            sent_at: data.sent_at ?? new Date().toISOString(),
            is_spam: data.is_spam ?? false,
          });
        }
      });

      setStatus("connected");

      cleanup = () => {
        try {
          channel.stopListening(".EmailReceived");
          echo.leave(`mail.${userId}`);
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
  }, [enabled, userId]);

  return { status };
}
