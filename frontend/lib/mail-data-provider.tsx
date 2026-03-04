"use client";

import { createContext, useContext, useState, useEffect, useCallback, useRef, useMemo, ReactNode } from "react";
import { toast } from "sonner";
import { api } from "@/lib/api";
import { useAuth } from "@/lib/auth";
import type { EmailLabel, AccessibleMailbox } from "@/lib/mail-types";
import type { ReplyData } from "@/components/mail/compose-dialog";
import { useMailStream, type EmailReceivedPayload } from "@/lib/use-mail-stream";

const ACTIVE_MAILBOX_KEY = "selfmx_active_mailbox_id";

interface MailDataContextType {
  labels: EmailLabel[];
  unreadCount: number;
  accessibleMailboxes: AccessibleMailbox[];
  activeMailboxId: number | null;
  setActiveMailboxId: (id: number | null) => void;
  mailboxUnreadCounts: Record<number, number>;
  refreshLabels: () => void;
  refreshUnreadCount: () => void;
  refreshMailboxes: () => void;
  composeOpen: boolean;
  setComposeOpen: (open: boolean) => void;
  composeMode: "compose" | "reply" | "reply_all" | "forward";
  replyData: ReplyData | null;
  openCompose: () => void;
  openReply: (data: ReplyData) => void;
  openReplyAll: (data: ReplyData) => void;
  openForward: (data: ReplyData) => void;
  onSent: () => void;
  setOnSent: (callback: () => void) => void;
  onNewEmailReceived: EmailReceivedPayload | null;
}

const MailDataContext = createContext<MailDataContextType | undefined>(undefined);

export function MailDataProvider({ children }: { children: ReactNode }) {
  const { user } = useAuth();
  const [labels, setLabels] = useState<EmailLabel[]>([]);
  const [unreadCount, setUnreadCount] = useState(0);
  const [accessibleMailboxes, setAccessibleMailboxes] = useState<AccessibleMailbox[]>([]);
  const [activeMailboxId, setActiveMailboxIdState] = useState<number | null>(null);
  const [mailboxUnreadCounts, setMailboxUnreadCounts] = useState<Record<number, number>>({});
  const [composeOpen, setComposeOpen] = useState(false);
  const [composeMode, setComposeMode] = useState<"compose" | "reply" | "reply_all" | "forward">("compose");
  const [replyData, setReplyData] = useState<ReplyData | null>(null);
  const onSentCallbackRef = useRef<() => void>(() => {});
  const [lastReceivedEmail, setLastReceivedEmail] = useState<EmailReceivedPayload | null>(null);

  // Restore active mailbox from localStorage on mount
  useEffect(() => {
    try {
      const stored = localStorage.getItem(ACTIVE_MAILBOX_KEY);
      if (stored) {
        const parsed = parseInt(stored, 10);
        if (!isNaN(parsed)) {
          setActiveMailboxIdState(parsed);
        }
      }
    } catch {
      // localStorage not available
    }
  }, []);

  const setActiveMailboxId = useCallback((id: number | null) => {
    setActiveMailboxIdState(id);
    try {
      if (id === null) {
        localStorage.removeItem(ACTIVE_MAILBOX_KEY);
      } else {
        localStorage.setItem(ACTIVE_MAILBOX_KEY, String(id));
      }
    } catch {
      // localStorage not available
    }
  }, []);

  const fetchLabels = useCallback(async () => {
    try {
      const res = await api.get<{ labels: EmailLabel[] }>("/email/labels");
      setLabels(res.data.labels);
    } catch {
      // Labels are optional
    }
  }, []);

  const fetchMailboxes = useCallback(async () => {
    try {
      const res = await api.get<{ mailboxes: AccessibleMailbox[] }>("/email/mailboxes");
      setAccessibleMailboxes(res.data.mailboxes);
    } catch {
      // Silent fail
    }
  }, []);

  const fetchUnreadCounts = useCallback(async () => {
    try {
      const res = await api.get<{ total: number; per_mailbox: Record<number, number> }>(
        "/email/unread-counts"
      );
      setUnreadCount(res.data.total);
      setMailboxUnreadCounts(res.data.per_mailbox || {});
    } catch {
      // Silent fail
    }
  }, []);

  // Subscribe to real-time email events via Reverb
  const handleNewEmail = useCallback((payload: EmailReceivedPayload) => {
    fetchUnreadCounts();
    setLastReceivedEmail(payload);

    // Show toast notification for non-spam emails
    if (!payload.is_spam) {
      const sender = payload.from_name || payload.from_address;
      toast(sender, {
        description: payload.subject || "(no subject)",
        action: {
          label: "View",
          onClick: () => {
            if (window.location.pathname !== "/mail") {
              window.location.href = "/mail";
            }
          },
        },
      });
    }
  }, [fetchUnreadCounts]);

  useMailStream(user?.id ?? null, !!user, handleNewEmail);

  // Fetch on mount and poll unread every 5 minutes as fallback
  useEffect(() => {
    if (!user) return;

    fetchLabels();
    fetchMailboxes();
    fetchUnreadCounts();

    const interval = setInterval(fetchUnreadCounts, 5 * 60 * 1000);
    return () => clearInterval(interval);
  }, [user, fetchLabels, fetchMailboxes, fetchUnreadCounts]);

  // Validate active mailbox still exists when mailbox list updates
  useEffect(() => {
    if (activeMailboxId !== null && accessibleMailboxes.length > 0) {
      const exists = accessibleMailboxes.some(m => m.id === activeMailboxId);
      if (!exists) {
        setActiveMailboxId(null);
      }
    }
  }, [accessibleMailboxes, activeMailboxId, setActiveMailboxId]);

  const openCompose = useCallback(() => {
    setComposeMode("compose");
    setReplyData(null);
    setComposeOpen(true);
  }, []);

  const openReply = useCallback((data: ReplyData) => {
    setReplyData(data);
    setComposeMode("reply");
    setComposeOpen(true);
  }, []);

  const openReplyAll = useCallback((data: ReplyData) => {
    setReplyData(data);
    setComposeMode("reply_all");
    setComposeOpen(true);
  }, []);

  const openForward = useCallback((data: ReplyData) => {
    setReplyData(data);
    setComposeMode("forward");
    setComposeOpen(true);
  }, []);

  const handleSent = useCallback(() => {
    fetchUnreadCounts();
    onSentCallbackRef.current();
  }, [fetchUnreadCounts]);

  const setOnSent = useCallback((callback: () => void) => {
    onSentCallbackRef.current = callback;
  }, []);

  // Compute the effective unread count based on active mailbox
  const effectiveUnreadCount = useMemo(() => {
    if (activeMailboxId === null) return unreadCount;
    return mailboxUnreadCounts[activeMailboxId] || 0;
  }, [activeMailboxId, unreadCount, mailboxUnreadCounts]);

  const value = useMemo(() => ({
    labels,
    unreadCount: effectiveUnreadCount,
    accessibleMailboxes,
    activeMailboxId,
    setActiveMailboxId,
    mailboxUnreadCounts,
    refreshLabels: fetchLabels,
    refreshUnreadCount: fetchUnreadCounts,
    refreshMailboxes: fetchMailboxes,
    composeOpen,
    setComposeOpen,
    composeMode,
    replyData,
    openCompose,
    openReply,
    openReplyAll,
    openForward,
    onSent: handleSent,
    setOnSent,
    onNewEmailReceived: lastReceivedEmail,
  }), [
    labels, effectiveUnreadCount, accessibleMailboxes, activeMailboxId,
    setActiveMailboxId, mailboxUnreadCounts,
    fetchLabels, fetchUnreadCounts, fetchMailboxes,
    composeOpen, composeMode, replyData,
    openCompose, openReply, openReplyAll, openForward,
    handleSent, setOnSent, lastReceivedEmail,
  ]);

  return (
    <MailDataContext.Provider value={value}>
      {children}
    </MailDataContext.Provider>
  );
}

export function useMailData() {
  const context = useContext(MailDataContext);
  if (context === undefined) {
    throw new Error("useMailData must be used within a MailDataProvider");
  }
  return context;
}
