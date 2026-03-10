"use client";

import { useRouter } from "next/navigation";
import { Check, ChevronRight } from "lucide-react";
import { cn } from "@/lib/utils";
import type { AppNotification } from "@/lib/notifications";
import { getNotificationType, getDefaultActionUrl } from "@/lib/notification-types";
import { activateWaitingServiceWorker } from "@/lib/service-worker";
import { Button } from "@/components/ui/button";

function formatRelative(date: Date): string {
  const now = new Date();
  const diff = now.getTime() - date.getTime();
  const mins = Math.floor(diff / 60000);
  const hours = Math.floor(diff / 3600000);
  const days = Math.floor(diff / 86400000);
  if (mins < 1) return "just now";
  if (mins < 60) return `${mins}m ago`;
  if (hours < 24) return `${hours}h ago`;
  if (days < 7) return `${days}d ago`;
  return date.toLocaleDateString();
}

export interface NotificationItemProps {
  notification: AppNotification;
  onMarkRead?: (id: string) => void;
  onClick?: (notification: AppNotification) => void;
  compact?: boolean;
  showMarkRead?: boolean;
}

export function NotificationItem({
  notification,
  onMarkRead,
  onClick,
  compact = false,
  showMarkRead = true,
}: NotificationItemProps) {
  const router = useRouter();
  const isUnread = !notification.read_at;
  const rawActionUrl =
    (notification.data?.action_url as string | undefined) ??
    getDefaultActionUrl(notification.type);
  // Only allow relative paths to prevent open redirect
  const actionUrl =
    rawActionUrl && rawActionUrl.startsWith("/") ? rawActionUrl : undefined;

  const handleClick = async () => {
    if (isUnread) onMarkRead?.(notification.id);
    onClick?.(notification);
    if (notification.data?.sw_update) {
      await activateWaitingServiceWorker();
      return;
    }
    if (actionUrl) router.push(actionUrl);
  };

  const handleMarkRead = (e: React.MouseEvent) => {
    e.stopPropagation();
    if (isUnread) onMarkRead?.(notification.id);
  };

  const typeMeta = getNotificationType(notification.type);
  const TypeIcon = typeMeta.icon;

  const content = (
    <div className="flex gap-3 w-full text-left">
      <div
        className={cn(
          "shrink-0 mt-0.5 rounded-full flex items-center justify-center",
          compact ? "h-7 w-7" : "h-8 w-8",
          typeMeta.bg,
        )}
      >
        <TypeIcon className={cn(compact ? "h-3.5 w-3.5" : "h-4 w-4", typeMeta.color)} />
      </div>
      <div
        className={cn(
          "flex-1 min-w-0",
          compact ? "space-y-0.5" : "space-y-1"
        )}
      >
        <div className={cn("flex items-center gap-2", compact && "justify-between")}>
          <p
            className={cn(
              "font-medium leading-tight truncate",
              isUnread ? "text-foreground" : "text-muted-foreground",
              compact ? "text-sm" : "text-base"
            )}
          >
            {notification.title}
          </p>
          {compact && (
            <p className="text-xs text-muted-foreground shrink-0">
              {formatRelative(new Date(notification.created_at))}
            </p>
          )}
        </div>
        <p
          className={cn(
            "text-muted-foreground truncate",
            compact ? "text-xs" : "text-sm"
          )}
        >
          {notification.message}
        </p>
        {!compact && (
          <p className="text-sm text-muted-foreground">
            {formatRelative(new Date(notification.created_at))}
          </p>
        )}
      </div>
      {isUnread && (
        <span
          className="shrink-0 mt-1.5 h-2 w-2 rounded-full bg-primary"
          aria-hidden
        />
      )}
      {showMarkRead && isUnread && onMarkRead && (
        <Button
          variant="ghost"
          size="icon"
          className="shrink-0 h-8 w-8 text-muted-foreground"
          onClick={handleMarkRead}
          title="Mark as read"
          aria-label="Mark as read"
        >
          <Check className="h-4 w-4" />
        </Button>
      )}
      {actionUrl && !isUnread && (
        <ChevronRight className="shrink-0 h-4 w-4 text-muted-foreground mt-1" />
      )}
    </div>
  );

  const isClickable = !!(onClick || actionUrl);

  const baseClass = cn(
    "rounded-lg transition-colors border",
    compact ? "px-3 py-2" : "px-4 py-3",
    isUnread && "bg-muted/50 border-muted",
    isClickable && "cursor-pointer hover:bg-muted/50"
  );

  if (isClickable) {
    return (
      <button
        type="button"
        className={cn(baseClass, "w-full")}
        onClick={handleClick}
      >
        {content}
      </button>
    );
  }

  return <div className={baseClass}>{content}</div>;
}
