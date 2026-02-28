"use client";

import {
  createContext,
  useContext,
  useCallback,
  useEffect,
  useState,
  useRef,
  type ReactNode,
} from "react";
import { api } from "@/lib/api";
import { useAuth } from "@/lib/auth";
import { getEcho, disconnectEcho } from "@/lib/echo";

export interface Notification {
  id: string;
  user_id: number;
  type: string;
  title: string;
  message: string;
  data: Record<string, unknown> | null;
  read_at: string | null;
  created_at: string;
  updated_at: string;
}

interface NotificationContextValue {
  notifications: Notification[];
  unreadCount: number;
  isLoading: boolean;
  fetchNotifications: () => Promise<void>;
  fetchUnreadCount: () => Promise<void>;
  markAsRead: (ids: string[]) => Promise<void>;
  markAllAsRead: () => Promise<void>;
  deleteNotification: (id: string) => Promise<void>;
  prependNotification?: (n: Notification) => void;
}

const NotificationContext = createContext<NotificationContextValue | null>(null);

const RECENT_LIMIT = 10;

export function useNotifications() {
  const ctx = useContext(NotificationContext);
  if (!ctx) {
    throw new Error("useNotifications must be used within NotificationProvider");
  }
  return ctx;
}

export function NotificationProvider({ children }: { children: ReactNode }) {
  const { user } = useAuth();
  const [notifications, setNotifications] = useState<Notification[]>([]);
  const [unreadCount, setUnreadCount] = useState(0);
  const [isLoading, setIsLoading] = useState(false);
  const mounted = useRef(true);

  const fetchUnreadCount = useCallback(async () => {
    if (!user) return;
    try {
      const { data } = await api.get<{ count: number }>("/notifications/unread-count");
      if (mounted.current) setUnreadCount(data.count);
    } catch {
      // Ignore; user may have logged out
    }
  }, [user]);

  const fetchNotifications = useCallback(async () => {
    if (!user) return;
    setIsLoading(true);
    try {
      const { data } = await api.get<{
        data: Notification[];
        current_page: number;
        per_page: number;
        total: number;
      }>("/notifications", { params: { per_page: RECENT_LIMIT, page: 1 } });
      if (mounted.current) {
        setNotifications(Array.isArray(data.data) ? data.data : []);
      }
      await fetchUnreadCount();
    } catch {
      if (mounted.current) {
        setNotifications([]);
      }
    } finally {
      if (mounted.current) setIsLoading(false);
    }
  }, [user, fetchUnreadCount]);

  const markAsRead = useCallback(
    async (ids: string[]) => {
      if (!user || ids.length === 0) return;
      try {
        await api.post("/notifications/mark-read", { ids });
        if (mounted.current) {
          let newlyRead = 0;
          setNotifications((prev) =>
            prev.map((n) => {
              if (!ids.includes(n.id)) return n;
              if (!n.read_at) newlyRead += 1;
              return { ...n, read_at: n.read_at ?? new Date().toISOString() };
            })
          );
          setUnreadCount((c) => Math.max(0, c - newlyRead));
        }
      } catch {
        await fetchUnreadCount();
      }
    },
    [user, fetchUnreadCount]
  );

  const markAllAsRead = useCallback(async () => {
    if (!user) return;
    try {
      await api.post("/notifications/mark-all-read");
      if (mounted.current) {
        setNotifications((prev) =>
          prev.map((n) => ({ ...n, read_at: n.read_at ?? new Date().toISOString() }))
        );
        setUnreadCount(0);
      }
    } catch {
      await fetchNotifications();
    }
  }, [user, fetchNotifications]);

  const deleteNotification = useCallback(
    async (id: string) => {
      if (!user) return;
      try {
        await api.delete(`/notifications/${id}`);
        if (mounted.current) {
          let wasUnread = false;
          setNotifications((prev) => {
            const n = prev.find((x) => x.id === id);
            wasUnread = !!n && !n.read_at;
            return prev.filter((x) => x.id !== id);
          });
          if (wasUnread) setUnreadCount((c) => Math.max(0, c - 1));
        }
      } catch {
        await fetchNotifications();
      }
    },
    [user, fetchNotifications]
  );

  const prependNotification = useCallback((n: Notification) => {
    setNotifications((prev) => {
      const exists = prev.some((x) => x.id === n.id);
      if (exists) return prev;
      return [n, ...prev].slice(0, RECENT_LIMIT);
    });
    setUnreadCount((c) => c + 1);
  }, []);

  useEffect(() => {
    mounted.current = true;
    return () => {
      mounted.current = false;
    };
  }, []);

  useEffect(() => {
    if (!user) {
      setNotifications([]);
      setUnreadCount(0);
      return;
    }
    fetchNotifications();
  }, [user, fetchNotifications]);

  useEffect(() => {
    if (!user?.id || typeof window === "undefined") return;

    let cancelled = false;
    let cleanup: (() => void) | undefined;

    getEcho().then((echo) => {
      if (cancelled || !echo) return;

      const channel = echo.private(`user.${user.id}`);
      channel.listen(".NotificationSent", (e: { id?: string; [k: string]: unknown }) => {
        if (!mounted.current || !e) return;
        const n = e as unknown as Notification;
        if (n?.id && prependNotification) {
          prependNotification({
            id: n.id,
            user_id: n.user_id,
            type: n.type ?? "info",
            title: n.title ?? "",
            message: n.message ?? "",
            data: (n.data as Record<string, unknown>) ?? null,
            read_at: (n.read_at as string) ?? null,
            created_at: n.created_at ?? new Date().toISOString(),
            updated_at: n.updated_at ?? new Date().toISOString(),
          });
        }
      });

      cleanup = () => {
        try {
          channel.stopListening(".NotificationSent");
          echo.leave(`user.${user.id}`);
        } catch {
          // ignore
        }
      };
    });

    return () => {
      cancelled = true;
      cleanup?.();
    };
  }, [user?.id, prependNotification]);

  // Listen for push messages from the service worker (fallback when Echo is unavailable)
  useEffect(() => {
    if (!user || typeof navigator === "undefined" || !("serviceWorker" in navigator)) return;
    const handler = (event: MessageEvent) => {
      if (event.data?.type === "PUSH_RECEIVED") {
        // Refresh notifications from the server to pick up the new one
        fetchNotifications();
      }
    };
    navigator.serviceWorker.addEventListener("message", handler);
    return () => navigator.serviceWorker.removeEventListener("message", handler);
  }, [user, fetchNotifications]);

  // Listen for service worker update events and show as a notification in the bell
  useEffect(() => {
    const handler = () => {
      prependNotification({
        id: `sw-update-${Date.now()}`,
        user_id: user?.id ?? 0,
        type: "system.update",
        title: "New version available",
        message: "A new version is ready. Click to refresh.",
        data: { sw_update: true },
        read_at: null,
        created_at: new Date().toISOString(),
        updated_at: new Date().toISOString(),
      });
    };
    window.addEventListener("sw-update-available", handler);
    return () => window.removeEventListener("sw-update-available", handler);
  }, [user?.id, prependNotification]);

  useEffect(() => {
    if (!user) disconnectEcho();
  }, [user]);

  useEffect(() => {
    return () => {
      disconnectEcho();
    };
  }, []);

  const value: NotificationContextValue = {
    notifications,
    unreadCount,
    isLoading,
    fetchNotifications,
    fetchUnreadCount,
    markAsRead,
    markAllAsRead,
    deleteNotification,
    prependNotification,
  };

  return (
    <NotificationContext.Provider value={value}>
      {children}
    </NotificationContext.Provider>
  );
}
