"use client";

import { Bell, Loader2 } from "lucide-react";
import { NotificationItem } from "./notification-item";
import type { AppNotification } from "@/lib/notifications";
import { cn } from "@/lib/utils";

export interface NotificationListProps {
  notifications: AppNotification[];
  isLoading?: boolean;
  onMarkRead?: (ids: string[]) => void;
  onItemClick?: (n: AppNotification) => void;
  compact?: boolean;
  emptyMessage?: string;
}

export function NotificationList({
  notifications,
  isLoading = false,
  onMarkRead,
  onItemClick,
  compact = false,
  emptyMessage = "No notifications yet",
}: NotificationListProps) {
  if (isLoading) {
    return (
      <div className="flex flex-col items-center justify-center py-12 gap-3">
        <Loader2 className="h-8 w-8 animate-spin text-muted-foreground" />
        <p className="text-sm text-muted-foreground">Loading notifications…</p>
      </div>
    );
  }

  if (!notifications.length) {
    return (
      <div
        className={cn(
          "flex flex-col items-center justify-center text-muted-foreground",
          compact ? "py-8" : "py-12"
        )}
      >
        <Bell className={cn("opacity-40", compact ? "h-10 w-10" : "h-12 w-12")} />
        <p className={cn("mt-2", compact ? "text-sm" : "text-base")}>
          {emptyMessage}
        </p>
      </div>
    );
  }

  return (
    <div className="space-y-2">
      {notifications.map((n) => (
        <NotificationItem
          key={n.id}
          notification={n}
          compact={compact}
          showMarkRead={!!onMarkRead}
          onMarkRead={onMarkRead ? (id) => onMarkRead([id]) : undefined}
          onClick={onItemClick}
        />
      ))}
    </div>
  );
}
