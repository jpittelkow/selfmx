"use client";

import { useState } from "react";
import { Bell } from "lucide-react";
import { useNotifications } from "@/lib/notifications";
import { useAppConfig } from "@/lib/app-config";
import { Button } from "@/components/ui/button";
import { NotificationDropdown } from "./notification-dropdown";
import dynamic from "next/dynamic";
import { cn } from "@/lib/utils";

const NovuInboxBell = dynamic(
  () => import("./novu-inbox").then((m) => ({ default: m.NovuInboxBell })),
  { ssr: false }
);

export function NotificationBell() {
  const { novu } = useAppConfig();
  // All hooks must be called before any conditional returns (Rules of Hooks)
  const [open, setOpen] = useState(false);
  const { unreadCount } = useNotifications();

  const useNovu = novu?.enabled && !!novu?.app_identifier;

  if (useNovu) {
    return <NovuInboxBell />;
  }

  const hasUnread = unreadCount > 0;

  const trigger = (
    <Button
      variant="ghost"
      size="icon"
      className={cn(
        "relative h-11 w-11 shrink-0",
        hasUnread && "text-foreground"
      )}
      aria-label={
        hasUnread
          ? `${unreadCount} unread notification${unreadCount === 1 ? "" : "s"}`
          : "Notifications"
      }
      title={hasUnread ? `${unreadCount} unread` : "Notifications"}
    >
      <Bell className="h-5 w-5" />
      {hasUnread && !open && (
        <span
          className="absolute -top-0.5 -right-0.5 h-4 w-4 animate-ping rounded-full bg-primary opacity-40"
          aria-hidden
        />
      )}
      {hasUnread && (
        <span
          className={cn(
            "absolute -top-0.5 -right-0.5 flex h-4 min-w-4 items-center justify-center rounded-full bg-primary px-1 text-[10px] font-medium text-primary-foreground",
            unreadCount > 99 && "px-1.5"
          )}
        >
          {unreadCount > 99 ? "99+" : unreadCount}
        </span>
      )}
    </Button>
  );

  return (
    <NotificationDropdown
      open={open}
      onOpenChange={setOpen}
      trigger={trigger}
    />
  );
}
